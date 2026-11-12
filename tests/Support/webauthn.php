<?php

use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use ParagonIE\ConstantTime\Base64UrlSafe;

const WEBAUTHN_RP_ID = 'attendance.test';
const WEBAUTHN_ORIGIN = 'https://attendance.test';

/**
 * Software authenticator (ES256, "none" attestation) producing real WebAuthn responses for the tests,
 * like the browser's virtual authenticator.
 */
class VirtualAuthenticator
{
    public string $credentialId;

    public int $counter = 0;

    public ?string $userHandle = null;

    /** @var OpenSSLAsymmetricKey */
    private $key;

    public function __construct(public string $rpId = WEBAUTHN_RP_ID, public string $origin = WEBAUTHN_ORIGIN)
    {
        $this->key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credentialId = random_bytes(32);
    }

    /** @param array<string, mixed> $publicKey creation options as returned by the API */
    public function create(array $publicKey, array $overrides = []): array
    {
        $this->userHandle = Base64UrlSafe::decodeNoPadding($publicKey['user']['id']);
        $clientData = json_encode([
            'type' => $overrides['type'] ?? 'webauthn.create',
            'challenge' => $overrides['challenge'] ?? $publicKey['challenge'],
            'origin' => $overrides['origin'] ?? $this->origin,
            'crossOrigin' => false,
        ]);
        $flags = $overrides['flags'] ?? 0x45; // UP | UV | AT
        $authData = hash('sha256', $overrides['rp_id'] ?? $this->rpId, true).chr($flags).pack('N', $this->counter)
            .str_repeat("\0", 16).pack('n', strlen($this->credentialId)).$this->credentialId.$this->coseKey();
        $attestation = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)]);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'attestationObject' => Base64UrlSafe::encodeUnpadded($attestation),
                'transports' => ['internal'],
            ],
        ];
    }

    /** @param array<string, mixed> $publicKey request options as returned by the API */
    public function get(array $publicKey, array $overrides = []): array
    {
        $this->counter = $overrides['counter'] ?? $this->counter + 1;
        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $overrides['challenge'] ?? $publicKey['challenge'],
            'origin' => $overrides['origin'] ?? $this->origin,
            'crossOrigin' => false,
        ]);
        $flags = $overrides['flags'] ?? 0x05; // UP | UV
        $authData = hash('sha256', $this->rpId, true).chr($flags).pack('N', $this->counter);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'rawId' => Base64UrlSafe::encodeUnpadded($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
                'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
                'signature' => Base64UrlSafe::encodeUnpadded($signature),
                'userHandle' => $this->userHandle !== null ? Base64UrlSafe::encodeUnpadded($this->userHandle) : null,
            ],
        ];
    }

    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);

        return self::cbor([1 => 2, 3 => -7, -1 => 1, -2 => new CborBytes(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)), -3 => new CborBytes(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))]);
    }

    public static function cbor(mixed $v): string
    {
        $head = function (int $major, int $len): string {
            if ($len < 24) {
                return chr(($major << 5) | $len);
            }
            if ($len < 256) {
                return chr(($major << 5) | 24).chr($len);
            }

            return chr(($major << 5) | 25).pack('n', $len);
        };
        if ($v instanceof CborBytes) {
            return $head(2, strlen($v->bytes)).$v->bytes;
        }
        if (is_int($v)) {
            return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v);
        }
        if (is_string($v)) {
            return $head(3, strlen($v)).$v;
        }
        $out = $head(5, count($v));
        foreach ($v as $k => $item) {
            $out .= self::cbor($k).self::cbor($item);
        }

        return $out;
    }
}

final class CborBytes
{
    public function __construct(public string $bytes) {}
}

function configureWebauthn(): void
{
    config(['webauthn.rp_id' => WEBAUTHN_RP_ID, 'webauthn.origins' => [WEBAUTHN_ORIGIN]]);
}

/** Registers Face ID for the user through the API on the token's device; returns the authenticator. */
function registerFaceId(object $test, string $token, ?VirtualAuthenticator $authenticator = null): VirtualAuthenticator
{
    $authenticator ??= new VirtualAuthenticator;
    $options = $test->withToken($token)->postJson('/api/me/faceid/register/options')->assertCreated();
    $test->withToken($token)->postJson('/api/me/faceid/register', [
        'challenge_id' => $options->json('challenge_id'),
        'credential' => $authenticator->create($options->json('public_key')),
        'name' => 'Test phone',
    ])->assertCreated();

    return $authenticator;
}

/** Full check-in with Face ID: intent (office position) then confirm with a signed assertion. */
function faceIdCheckIn(object $test, string $token, VirtualAuthenticator $authenticator, array $intentBody = ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])
{
    $intent = $test->withToken($token)->postJson('/api/attendance/check-in/intent', $intentBody)->assertCreated();

    return $test->withToken($token)->postJson('/api/attendance/check-in/confirm', [
        'intent_id' => $intent->json('intent_id'),
        'assertion' => $authenticator->get($intent->json('faceid_options.public_key')),
    ]);
}

function withoutFaceId(): void
{
    app(SettingsService::class)->set(['faceid_required_for_checkin' => false], null, Carbon::parse('2000-01-01'));
}

function faceIdUser(): User
{
    return User::factory()->create();
}
