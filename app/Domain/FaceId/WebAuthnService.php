<?php

namespace App\Domain\FaceId;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Enums\Permission;
use App\Exceptions\ApiError;
use App\Models\CheckinIntent;
use App\Models\User;
use App\Models\WebauthnChallenge;
use App\Models\WebauthnCredential;
use App\Notifications\StaffAlert;
use App\Services\ActivityLogger;
use App\Settings\SettingsService;
use Cose\Algorithms;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Face ID = WebAuthn passkey with user verification required (SPECS 6, security.md "Face ID").
 *
 * - Registration at first sign-in; the first credential of an account is approved automatically (setting),
 *   any later one is pending until an admin holding `approve_faceid_devices` approves it (the old one is
 *   then revoked). A credential is bound to the device (session) that registered it.
 * - Every challenge is random, single use, short-lived and bound to user + device (+ check-in intent).
 * - Only the public key is stored. Verification errors are logged without details to the client.
 */
class WebAuthnService
{
    private ?SerializerInterface $serializer = null;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ActivityLogger $logger,
    ) {}

    public static function b64(string $raw): string
    {
        return Base64UrlSafe::encodeUnpadded($raw);
    }

    /** Face ID readiness of the user on this device: ready | not_registered | pending_approval | other_device. */
    public function state(User $user, ?int $deviceId): string
    {
        $approved = $this->approvedFor($user);
        if ($approved !== null && $deviceId !== null && $approved->device_id === $deviceId) {
            return 'ready';
        }
        $pending = WebauthnCredential::query()->where('user_id', $user->id)->where('status', WebauthnCredential::PENDING)->first();
        if ($pending !== null && $pending->device_id === $deviceId) {
            return 'pending_approval';
        }

        return $approved !== null ? 'other_device' : 'not_registered';
    }

    /** @return array{challenge_id: string, expires_at: string, public_key: array<string, mixed>} */
    public function registrationOptions(User $user, int $deviceId): array
    {
        $now = Carbon::now();
        $ttl = $this->settings->int('faceid_challenge_ttl_seconds', $now);
        $challenge = random_bytes(32);

        $exclude = WebauthnCredential::query()->where('user_id', $user->id)->where('status', '!=', WebauthnCredential::REVOKED)->get()
            ->map(fn (WebauthnCredential $c) => PublicKeyCredentialDescriptor::create('public-key', Base64UrlSafe::decodeNoPadding($c->credential_id), $c->transports ?? []))
            ->all();

        $options = PublicKeyCredentialCreationOptions::create(
            $this->rp(),
            PublicKeyCredentialUserEntity::create($user->email, $this->userHandle($user), $user->fullName()),
            $challenge,
            [PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256), PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256)],
            AuthenticatorSelectionCriteria::create(
                AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $exclude,
            max(1, $ttl) * 1000,
        );

        $row = $this->storeChallenge($user, $deviceId, WebauthnChallenge::REGISTRATION, $challenge, $now->copy()->addSeconds($ttl), null);

        return ['challenge_id' => $row->id, 'expires_at' => $row->expires_at->toIso8601String(), 'public_key' => $this->toArray($options)];
    }

    /**
     * Verifies the attestation, then stores the public key (approved for the first device, pending otherwise).
     *
     * @param  array<string, mixed>  $credential  PublicKeyCredential JSON from navigator.credentials.create()
     */
    public function register(User $user, int $deviceId, string $challengeId, array $credential, ?string $name): WebauthnCredential
    {
        $now = Carbon::now();
        $challenge = $this->consumeChallenge($challengeId, $user, $deviceId, WebauthnChallenge::REGISTRATION, null, $now);
        if ($challenge === null) {
            throw new ApiError(422, 'This Face ID request has expired. Please start again.', 'challenge_invalid');
        }

        $record = null;
        try {
            $pkc = $this->serializer()->deserialize((string) json_encode($credential), PublicKeyCredential::class, 'json');
            if ($pkc->response instanceof AuthenticatorAttestationResponse) {
                $options = PublicKeyCredentialCreationOptions::create(
                    $this->rp(),
                    PublicKeyCredentialUserEntity::create($user->email, $this->userHandle($user), $user->fullName()),
                    Base64UrlSafe::decodeNoPadding($challenge->challenge),
                    [PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256), PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256)],
                    AuthenticatorSelectionCriteria::create(
                        AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                        AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                        AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
                    ),
                    PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
                );
                $authData = $pkc->response->attestationObject->authData;
                if ($authData->isUserVerified()) {
                    $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies()->creationCeremony())
                        ->check($pkc->response, $options, $this->rpId());
                }
            }
        } catch (Throwable $e) {
            Log::info('WebAuthn registration refused', ['user_id' => $user->id, 'error' => $e::class]);
            $record = null;
        }
        if ($record === null) {
            $this->logger->security('faceid.registration_failed', 'Face ID registration refused (verification failed)', LogLevel::Warning, $user, $user);
            throw new ApiError(422, 'Face ID registration failed. Please try again.', 'faceid_failed');
        }

        $hasAny = WebauthnCredential::query()->where('user_id', $user->id)->exists();
        $autoApprove = ! $hasAny && $this->settings->bool('faceid_first_device_auto_approve', $now);

        try {
            $stored = DB::transaction(function () use ($user, $deviceId, $record, $name, $autoApprove, $now) {
                // A new request replaces an earlier pending one.
                WebauthnCredential::query()->where('user_id', $user->id)->where('status', WebauthnCredential::PENDING)->update([
                    'status' => WebauthnCredential::REVOKED, 'revoked_at' => $now, 'revoke_reason' => 'superseded', 'updated_at' => $now,
                ]);

                return WebauthnCredential::query()->create([
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                    'credential_id' => self::b64($record->publicKeyCredentialId),
                    'public_key' => self::b64($record->credentialPublicKey),
                    'user_handle' => self::b64($record->userHandle),
                    'aaguid' => $record->aaguid->toRfc4122(),
                    'transports' => array_values(array_filter($record->transports, 'is_string')),
                    'attestation_type' => $record->attestationType,
                    'sign_count' => max(0, $record->counter),
                    'backup_eligible' => $record->backupEligible,
                    'backup_status' => $record->backupStatus,
                    'uv_initialized' => $record->uvInitialized,
                    'name' => $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 100) : null,
                    'status' => $autoApprove ? WebauthnCredential::APPROVED : WebauthnCredential::PENDING,
                    'approved_at' => $autoApprove ? $now : null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Same passkey registered twice, or a parallel first registration.
            throw new ApiError(409, 'This Face ID is already registered.', 'faceid_already_registered');
        }

        $this->logger->log('faceid.registered', $autoApprove ? 'Face ID registered (first device, approved)' : 'Face ID registered on a new device (pending approval)', LogType::Security, LogLevel::Info, $user, $user, [
            'credential_id' => $stored->id, 'device_id' => $deviceId, 'status' => $stored->status,
        ]);

        if (! $autoApprove) {
            $recipients = User::query()->holdingPermission(Permission::ApproveFaceIdDevices)->where('id', '!=', $user->id)->get();
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new StaffAlert(
                    'faceid_device_request',
                    'New Face ID device to approve',
                    "{$user->fullName()} registered Face ID on a new device.",
                    ['credential_id' => $stored->id, 'user_id' => $user->id],
                    false,
                    '/admin/faceid-devices',
                ));
            }
        }

        return $stored;
    }

    /**
     * Assertion options for a check-in intent, or null when the user has no approved credential on this device.
     *
     * @return array{challenge_id: string, public_key: array<string, mixed>}|null
     */
    public function checkinOptions(User $user, CheckinIntent $intent): ?array
    {
        $credential = $this->approvedFor($user);
        if ($credential === null || $intent->device_id === null || $credential->device_id !== (int) $intent->device_id) {
            return null;
        }
        $now = Carbon::now();
        $ttl = min($this->settings->int('faceid_challenge_ttl_seconds', $now), max(1, $now->diffInSeconds($intent->expires_at, true)));
        $challenge = random_bytes(32);
        $options = $this->requestOptions($challenge, $credential, (int) ceil($ttl * 1000));
        $row = $this->storeChallenge($user, (int) $intent->device_id, WebauthnChallenge::CHECKIN, $challenge, $now->copy()->addSeconds((int) ceil($ttl)), $intent->id);

        return ['challenge_id' => $row->id, 'public_key' => $this->toArray($options)];
    }

    /**
     * Verifies the check-in assertion against the challenge bound to this intent, the user's approved credential
     * and the intent's device. Does not consume anything: the caller does it atomically with the intent claim.
     *
     * @param  array<string, mixed>  $assertion  PublicKeyCredential JSON from navigator.credentials.get()
     */
    public function verifyCheckin(User $user, CheckinIntent $intent, array $assertion): ?VerifiedAssertion
    {
        $now = Carbon::now();
        /** @var WebauthnChallenge|null $challenge */
        $challenge = WebauthnChallenge::query()->where('checkin_intent_id', $intent->id)->where('user_id', $user->id)
            ->where('purpose', WebauthnChallenge::CHECKIN)->whereNull('consumed_at')->where('expires_at', '>', $now)->first();
        $credential = $this->approvedFor($user);
        if ($challenge === null || $credential === null || $intent->device_id === null
            || $credential->device_id !== (int) $intent->device_id || $challenge->device_id !== (int) $intent->device_id) {
            return null;
        }

        try {
            $pkc = $this->serializer()->deserialize((string) json_encode($assertion), PublicKeyCredential::class, 'json');
            if (! $pkc->response instanceof AuthenticatorAssertionResponse) {
                return null;
            }
            if (! hash_equals($credential->credential_id, self::b64($pkc->rawId)) || ! $pkc->response->authenticatorData->isUserVerified()) {
                return null;
            }
            $raw = Base64UrlSafe::decodeNoPadding($challenge->challenge);
            $updated = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())->check(
                $this->record($credential),
                $pkc->response,
                $this->requestOptions($raw, $credential, null),
                $this->rpId(),
                $pkc->response->userHandle,
            );
        } catch (Throwable $e) {
            Log::info('WebAuthn assertion refused', ['user_id' => $user->id, 'error' => $e::class]);

            return null;
        }

        return new VerifiedAssertion($credential->id, $challenge->id, max(0, $updated->counter));
    }

    /** Inside the check-in transaction: consume the challenge and move the sign counter forward. */
    public function markUsed(VerifiedAssertion $verified): bool
    {
        $now = Carbon::now();
        $consumed = WebauthnChallenge::query()->whereKey($verified->challengeId)->whereNull('consumed_at')->update(['consumed_at' => $now]);
        if ($consumed !== 1) {
            return false;
        }
        DB::update(
            'UPDATE webauthn_credentials SET sign_count = GREATEST(sign_count, ?), last_used_at = ?, updated_at = ? WHERE id = ? AND status = ?',
            [$verified->signCount, $now->toIso8601String(), $now->toIso8601String(), $verified->credentialId, WebauthnCredential::APPROVED],
        );

        return true;
    }

    public function approve(User $actor, WebauthnCredential $credential): WebauthnCredential
    {
        $now = Carbon::now();
        $done = DB::transaction(function () use ($actor, $credential, $now) {
            $locked = WebauthnCredential::query()->whereKey($credential->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== WebauthnCredential::PENDING) {
                return false;
            }
            WebauthnCredential::query()->where('user_id', $locked->user_id)->where('status', WebauthnCredential::APPROVED)->update([
                'status' => WebauthnCredential::REVOKED, 'revoked_by' => $actor->id, 'revoked_at' => $now, 'revoke_reason' => 'replaced', 'updated_at' => $now,
            ]);
            $locked->forceFill(['status' => WebauthnCredential::APPROVED, 'approved_by' => $actor->id, 'approved_at' => $now])->save();

            return true;
        });
        if (! $done) {
            throw new ApiError(409, 'This Face ID device has already been reviewed.', 'faceid_already_reviewed');
        }
        $credential->refresh();
        $this->logger->log('faceid.approved', 'New Face ID device approved (previous one revoked)', LogType::Security, LogLevel::Info, $credential->user, $actor, ['credential_id' => $credential->id]);
        $credential->user?->notify(new StaffAlert('faceid_device_approved', 'Face ID device approved', 'Your new Face ID device was approved. You can check in with it now.', ['credential_id' => $credential->id], false, '/attendance'));

        return $credential;
    }

    public function reject(User $actor, WebauthnCredential $credential): WebauthnCredential
    {
        $now = Carbon::now();
        $updated = WebauthnCredential::query()->whereKey($credential->id)->where('status', WebauthnCredential::PENDING)->update([
            'status' => WebauthnCredential::REVOKED, 'revoked_by' => $actor->id, 'revoked_at' => $now, 'revoke_reason' => 'rejected', 'updated_at' => $now,
        ]);
        if ($updated !== 1) {
            throw new ApiError(409, 'This Face ID device has already been reviewed.', 'faceid_already_reviewed');
        }
        $credential->refresh();
        $this->logger->log('faceid.rejected', 'New Face ID device rejected', LogType::Security, LogLevel::Warning, $credential->user, $actor, ['credential_id' => $credential->id]);
        $credential->user?->notify(new StaffAlert('faceid_device_rejected', 'Face ID device rejected', 'Your new Face ID device was not approved. Please contact your administrator.', ['credential_id' => $credential->id], false, '/attendance'));

        return $credential;
    }

    public function revoke(User $actor, WebauthnCredential $credential): WebauthnCredential
    {
        $now = Carbon::now();
        $updated = WebauthnCredential::query()->whereKey($credential->id)->where('status', '!=', WebauthnCredential::REVOKED)->update([
            'status' => WebauthnCredential::REVOKED, 'revoked_by' => $actor->id, 'revoked_at' => $now, 'revoke_reason' => 'revoked', 'updated_at' => $now,
        ]);
        if ($updated !== 1) {
            throw new ApiError(409, 'This Face ID device is already revoked.', 'faceid_already_revoked');
        }
        $credential->refresh();
        $this->logger->log('faceid.revoked', 'Face ID device revoked', LogType::Security, LogLevel::Warning, $credential->user, $actor, ['credential_id' => $credential->id]);
        $credential->user?->notify(new StaffAlert('faceid_device_revoked', 'Face ID device revoked', 'Your Face ID registration was revoked. Please register Face ID again.', ['credential_id' => $credential->id], false, '/attendance'));

        return $credential;
    }

    public function approvedFor(User $user): ?WebauthnCredential
    {
        return WebauthnCredential::query()->where('user_id', $user->id)->where('status', WebauthnCredential::APPROVED)->first();
    }

    private function consumeChallenge(string $id, User $user, int $deviceId, string $purpose, ?string $intentId, Carbon $now): ?WebauthnChallenge
    {
        $updated = WebauthnChallenge::query()->whereKey($id)->where('user_id', $user->id)->where('device_id', $deviceId)
            ->where('purpose', $purpose)->when($intentId !== null, fn ($q) => $q->where('checkin_intent_id', $intentId))
            ->whereNull('consumed_at')->where('expires_at', '>', $now)->update(['consumed_at' => $now]);

        return $updated === 1 ? WebauthnChallenge::query()->find($id) : null;
    }

    private function storeChallenge(User $user, int $deviceId, string $purpose, string $challenge, Carbon $expires, ?string $intentId): WebauthnChallenge
    {
        /** @var WebauthnChallenge $row */
        $row = WebauthnChallenge::query()->create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'purpose' => $purpose,
            'checkin_intent_id' => $intentId,
            'challenge' => self::b64($challenge),
            'expires_at' => $expires,
            'created_at' => Carbon::now(),
        ]);

        return $row;
    }

    private function requestOptions(string $challenge, WebauthnCredential $credential, ?int $timeoutMs): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            $challenge,
            $this->rpId(),
            [PublicKeyCredentialDescriptor::create('public-key', Base64UrlSafe::decodeNoPadding($credential->credential_id), $credential->transports ?? [])],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            $timeoutMs !== null ? max(1, $timeoutMs) : null,
        );
    }

    private function record(WebauthnCredential $c): CredentialRecord
    {
        return CredentialRecord::create(
            Base64UrlSafe::decodeNoPadding($c->credential_id),
            'public-key',
            $c->transports ?? [],
            $c->attestation_type,
            EmptyTrustPath::create(),
            Uuid::fromString($c->aaguid ?? '00000000-0000-0000-0000-000000000000'),
            Base64UrlSafe::decodeNoPadding($c->public_key),
            Base64UrlSafe::decodeNoPadding($c->user_handle),
            $c->sign_count,
            null,
            $c->backup_eligible,
            $c->backup_status,
            $c->uv_initialized,
        );
    }

    /** Opaque, stable WebAuthn user handle (no personal data). */
    private function userHandle(User $user): string
    {
        return hash_hmac('sha256', 'webauthn-user|'.$user->id, (string) config('app.key'), true);
    }

    private function rp(): PublicKeyCredentialRpEntity
    {
        return PublicKeyCredentialRpEntity::create((string) config('webauthn.rp_name'), $this->rpId());
    }

    private function rpId(): string
    {
        return (string) config('webauthn.rp_id');
    }

    private function ceremonies(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        /** @var list<string> $origins */
        $origins = (array) config('webauthn.origins', []);
        $factory->setAllowedOrigins($origins);

        return $factory;
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(new AttestationStatementSupportManager([new NoneAttestationStatementSupport])))->create();
    }

    /** @return array<string, mixed> */
    private function toArray(object $options): array
    {
        $json = $this->serializer()->serialize($options, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => JSON_THROW_ON_ERROR,
        ]);

        return (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
