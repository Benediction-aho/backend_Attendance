<?php

use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\User;
use App\Models\WebauthnChallenge;
use App\Models\WebauthnCredential;
use App\Notifications\StaffAlert;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();
    configureWebauthn();
    at('2026-11-16 07:55');
});

function switchUser(object $test): void
{
    app('auth')->forgetGuards();
    $test->flushHeaders();
}

describe('registration', function () {
    it('requires authentication and is reserved to employees', function () {
        $this->postJson('/api/me/faceid/register/options')->assertUnauthorized();
        $this->getJson('/api/me/faceid')->assertUnauthorized();
        $admin = User::factory()->admin(...Permission::cases())->create();
        $this->withToken(deviceToken($admin))->postJson('/api/me/faceid/register/options')->assertForbidden()->assertJsonPath('code', 'employees_only');
    });

    it('returns creation options with user verification required, platform authenticator, no attestation', function () {
        $user = User::factory()->create();
        $r = $this->withToken(deviceToken($user))->postJson('/api/me/faceid/register/options')->assertCreated();
        expect($r->json('public_key.rp.id'))->toBe(WEBAUTHN_RP_ID)
            ->and($r->json('public_key.authenticatorSelection.userVerification'))->toBe('required')
            ->and($r->json('public_key.authenticatorSelection.authenticatorAttachment'))->toBe('platform')
            ->and($r->json('public_key.attestation'))->toBe('none')
            ->and($r->json('public_key.user.name'))->toBe($user->email)
            ->and(strlen($r->json('public_key.challenge')))->toBeGreaterThanOrEqual(43)
            ->and(WebauthnChallenge::query()->sole()->expires_at->equalTo(Carbon::now()->addSeconds(120)))->toBeTrue();
    });

    it('refuses a token without a device', function () {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/me/faceid/register/options')->assertForbidden()->assertJsonPath('code', 'device_required');
    });

    it('registers the first device as approved and stores only the public key', function () {
        $user = User::factory()->create();
        $token = deviceToken($user);
        registerFaceId($this, $token);
        $c = WebauthnCredential::query()->sole();
        expect($c->status)->toBe('approved')->and($c->device_id)->not->toBeNull()->and($c->public_key)->not->toBe('')
            ->and(ActivityLog::query()->where('event', 'faceid.registered')->exists())->toBeTrue();
        $this->withToken($token)->getJson('/api/me/faceid')->assertOk()->assertJsonPath('state', 'ready')
            ->assertJsonPath('data.0.status', 'approved')->assertJsonPath('data.0.this_device', true)
            ->assertJsonMissingPath('data.0.public_key');
        $this->withToken($token)->postJson('/api/me/faceid/register/options')->assertStatus(409);
    });

    it('rejects a replayed or expired registration challenge', function () {
        $user = User::factory()->create();
        $token = deviceToken($user);
        $options = $this->withToken($token)->postJson('/api/me/faceid/register/options')->json();
        $auth = new VirtualAuthenticator;
        $body = ['challenge_id' => $options['challenge_id'], 'credential' => $auth->create($options['public_key'])];
        $this->withToken($token)->postJson('/api/me/faceid/register', $body)->assertCreated();
        $this->withToken($token)->postJson('/api/me/faceid/register', $body)->assertStatus(422)->assertJsonPath('code', 'challenge_invalid');

        $user2 = User::factory()->create();
        switchUser($this);
        $token2 = deviceToken($user2, 'device-test-0000000002');
        $options = $this->withToken($token2)->postJson('/api/me/faceid/register/options')->json();
        at('2026-11-16 07:58');
        $this->withToken($token2)->postJson('/api/me/faceid/register', ['challenge_id' => $options['challenge_id'], 'credential' => (new VirtualAuthenticator)->create($options['public_key'])])
            ->assertStatus(422)->assertJsonPath('code', 'challenge_invalid');
    });

    it('rejects a challenge issued to another user or device', function () {
        $alice = User::factory()->create();
        $options = $this->withToken(deviceToken($alice))->postJson('/api/me/faceid/register/options')->json();
        switchUser($this);
        $bob = User::factory()->create();
        $this->withToken(deviceToken($bob, 'device-test-0000000002'))->postJson('/api/me/faceid/register', ['challenge_id' => $options['challenge_id'], 'credential' => (new VirtualAuthenticator)->create($options['public_key'])])
            ->assertStatus(422)->assertJsonPath('code', 'challenge_invalid');
        expect(WebauthnCredential::query()->count())->toBe(0);
    });

    it('refuses registration without user verification, from another origin, for another RP or with a forged challenge', function (array $overrides) {
        $user = User::factory()->create();
        $token = deviceToken($user);
        $options = $this->withToken($token)->postJson('/api/me/faceid/register/options')->json();
        $this->withToken($token)->postJson('/api/me/faceid/register', ['challenge_id' => $options['challenge_id'], 'credential' => (new VirtualAuthenticator)->create($options['public_key'], $overrides)])
            ->assertStatus(422)->assertJsonPath('code', 'faceid_failed');
        expect(WebauthnCredential::query()->count())->toBe(0);
    })->with([
        'no user verification' => [['flags' => 0x41]],
        'other origin' => [['origin' => 'https://evil.test']],
        'other rp id' => [['rp_id' => 'evil.test']],
        'forged challenge' => [['challenge' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']],
        'wrong ceremony type' => [['type' => 'webauthn.get']],
    ]);

    it('creates a second device as pending, notifies the approvers and supersedes an earlier pending request', function () {
        $approver = User::factory()->admin(Permission::ApproveFaceIdDevices)->create();
        $other = User::factory()->admin(Permission::ViewLateness)->create();
        $user = User::factory()->create();
        registerFaceId($this, deviceToken($user));
        switchUser($this);
        $token2 = deviceToken($user, 'device-test-0000000002');
        registerFaceId($this, $token2);
        expect(WebauthnCredential::query()->where('status', 'pending')->count())->toBe(1);
        $this->withToken($token2)->getJson('/api/me/faceid')->assertJsonPath('state', 'pending_approval');
        Notification::assertSentTo($approver, StaffAlert::class, fn (StaffAlert $n) => $n->kind === 'faceid_device_request');
        Notification::assertNotSentTo($other, StaffAlert::class);

        registerFaceId($this, $token2);
        expect(WebauthnCredential::query()->where('status', 'pending')->count())->toBe(1)
            ->and(WebauthnCredential::query()->where('revoke_reason', 'superseded')->count())->toBe(1)
            ->and(WebauthnCredential::query()->where('status', 'approved')->count())->toBe(1);
    });
});

describe('admin review', function () {
    beforeEach(function () {
        $this->employee = User::factory()->create();
        registerFaceId($this, deviceToken($this->employee));
        switchUser($this);
        $this->newToken = deviceToken($this->employee, 'device-test-0000000002');
        $this->newAuth = registerFaceId($this, $this->newToken);
        switchUser($this);
        $this->pending = WebauthnCredential::query()->where('status', 'pending')->sole();
    });

    it('lists pending devices only for approvers, employees only for admins', function () {
        Sanctum::actingAs(User::factory()->admin(Permission::ViewLateness)->create());
        $this->getJson('/api/admin/faceid/credentials')->assertForbidden();
        $this->postJson("/api/admin/faceid/credentials/{$this->pending->id}/approve")->assertForbidden();
        switchUser($this);
        Sanctum::actingAs(User::factory()->admin(Permission::ApproveFaceIdDevices)->create());
        $this->getJson('/api/admin/faceid/credentials')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $this->employee->id)->assertJsonMissingPath('data.0.public_key');
        $this->getJson('/api/admin/faceid/credentials?status=approved')->assertOk()->assertJsonCount(1, 'data');
        switchUser($this);
        Sanctum::actingAs($this->employee);
        $this->getJson('/api/admin/faceid/credentials')->assertForbidden();
    });

    it('approves: the old credential is revoked, the user notified, the action logged; only once', function () {
        $admin = User::factory()->admin(Permission::ApproveFaceIdDevices)->create();
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/faceid/credentials/{$this->pending->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/admin/faceid/credentials/{$this->pending->id}/approve")->assertStatus(409);
        $this->postJson("/api/admin/faceid/credentials/{$this->pending->id}/reject")->assertStatus(409);
        expect(WebauthnCredential::query()->where('status', 'approved')->sole()->id)->toBe($this->pending->id)
            ->and(WebauthnCredential::query()->where('revoke_reason', 'replaced')->sole()->revoked_by)->toBe($admin->id)
            ->and(ActivityLog::query()->where('event', 'faceid.approved')->where('actor_id', $admin->id)->exists())->toBeTrue();
        Notification::assertSentTo($this->employee, StaffAlert::class, fn (StaffAlert $n) => $n->kind === 'faceid_device_approved');

        // The new device can now check in with Face ID; the old one cannot.
        switchUser($this);
        faceIdCheckIn($this, $this->newToken, $this->newAuth)->assertCreated()->assertJsonPath('data.faceid_verified', true);
    });

    it('rejects a pending device', function () {
        Sanctum::actingAs(User::factory()->admin(Permission::ApproveFaceIdDevices)->create());
        $this->postJson("/api/admin/faceid/credentials/{$this->pending->id}/reject")->assertOk()->assertJsonPath('data.status', 'revoked')->assertJsonPath('data.revoke_reason', 'rejected');
        expect(WebauthnCredential::query()->where('status', 'approved')->count())->toBe(1);
        Notification::assertSentTo($this->employee, StaffAlert::class, fn (StaffAlert $n) => $n->kind === 'faceid_device_rejected');
    });

    it('never lets an admin review an admin device nor his own', function () {
        $admin = User::factory()->admin(Permission::ApproveFaceIdDevices)->create();
        $staff = WebauthnCredential::query()->create(['user_id' => $admin->id, 'credential_id' => 'abc', 'public_key' => 'x', 'user_handle' => 'y', 'status' => 'pending']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/faceid/credentials/{$staff->id}/approve")->assertForbidden();
        $other = User::factory()->admin()->create();
        $foreign = WebauthnCredential::query()->create(['user_id' => $other->id, 'credential_id' => 'abd', 'public_key' => 'x', 'user_handle' => 'y', 'status' => 'pending']);
        $this->postJson("/api/admin/faceid/credentials/{$foreign->id}/approve")->assertForbidden();
    });

    it('lets only super admins revoke any credential', function () {
        $approved = WebauthnCredential::query()->where('status', 'approved')->sole();
        Sanctum::actingAs(User::factory()->admin(...Permission::cases())->create());
        $this->postJson("/api/admin/faceid/credentials/{$approved->id}/revoke")->assertForbidden();
        switchUser($this);
        $super = User::factory()->superAdmin()->create();
        Sanctum::actingAs($super);
        $this->postJson("/api/admin/faceid/credentials/{$approved->id}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->postJson("/api/admin/faceid/credentials/{$approved->id}/revoke")->assertStatus(409);
        expect(ActivityLog::query()->where('event', 'faceid.revoked')->where('actor_id', $super->id)->exists())->toBeTrue();
    });
});

describe('check-in with Face ID', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->token = deviceToken($this->user);
    });

    it('is required by default', function () {
        expect(app(SettingsService::class)->bool('faceid_required_for_checkin'))->toBeTrue();
        $this->withToken($this->token)->getJson('/api/attendance/today')->assertJsonPath('rules.faceid_required', true)->assertJsonPath('faceid_state', 'not_registered');
        $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])
            ->assertForbidden()->assertJsonPath('code', 'faceid_not_registered');
    });

    it('checks in with a valid assertion and records the credential and the sign count', function () {
        $auth = registerFaceId($this, $this->token);
        $intent = $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])->assertCreated()
            ->assertJsonPath('faceid_required', true)->assertJsonPath('faceid_options.public_key.userVerification', 'required')
            ->assertJsonPath('faceid_options.public_key.rpId', WEBAUTHN_RP_ID);
        expect($intent->json('faceid_options.public_key.allowCredentials'))->toHaveCount(1);
        $this->withToken($this->token)->postJson('/api/attendance/check-in/confirm', ['intent_id' => $intent->json('intent_id'), 'assertion' => $auth->get($intent->json('faceid_options.public_key'))])
            ->assertCreated()->assertJsonPath('data.faceid_verified', true);
        $credential = WebauthnCredential::query()->sole();
        expect(Attendance::query()->sole()->webauthn_credential_id)->toBe($credential->id)
            ->and($credential->sign_count)->toBe(1)->and($credential->last_used_at)->not->toBeNull()
            ->and(WebauthnChallenge::query()->where('purpose', 'checkin')->sole()->consumed_at)->not->toBeNull();
    });

    it('works for mission and remote check-ins too', function () {
        $auth = registerFaceId($this, $this->token);
        faceIdCheckIn($this, $this->token, $auth, ['type' => 'mission', 'reason' => 'Client visit'])->assertCreated()->assertJsonPath('data.check_in_type', 'mission')->assertJsonPath('data.faceid_verified', true);
    });

    it('refuses a confirmation without assertion, a wrong signature, no user verification or a wrong origin', function (?array $overrides) {
        $auth = registerFaceId($this, $this->token);
        $intent = $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5]);
        $body = ['intent_id' => $intent->json('intent_id')];
        if ($overrides !== null) {
            $body['assertion'] = $auth->get($intent->json('faceid_options.public_key'), $overrides);
        }
        $this->withToken($this->token)->postJson('/api/attendance/check-in/confirm', $body)->assertStatus(422)->assertJsonPath('code', 'faceid_failed');
        expect(Attendance::query()->count())->toBe(0);
    })->with([
        'no assertion' => [null],
        'no user verification' => [['flags' => 0x01]],
        'other origin' => [['origin' => 'https://evil.test']],
        'other challenge' => [['challenge' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']],
    ]);

    it('refuses an assertion made by another authenticator (foreign key)', function () {
        registerFaceId($this, $this->token);
        $intent = $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5]);
        $this->withToken($this->token)->postJson('/api/attendance/check-in/confirm', ['intent_id' => $intent->json('intent_id'), 'assertion' => (new VirtualAuthenticator)->get($intent->json('faceid_options.public_key'))])
            ->assertStatus(422)->assertJsonPath('code', 'faceid_failed');
    });

    it('refuses another user\'s credential', function () {
        $aliceAuth = registerFaceId($this, $this->token);
        switchUser($this);
        $bob = User::factory()->create();
        $bobToken = deviceToken($bob, 'device-test-0000000002');
        registerFaceId($this, $bobToken);
        $intent = $this->withToken($bobToken)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5]);
        // Alice's authenticator signs Bob's challenge.
        $this->withToken($bobToken)->postJson('/api/attendance/check-in/confirm', ['intent_id' => $intent->json('intent_id'), 'assertion' => $aliceAuth->get($intent->json('faceid_options.public_key'))])
            ->assertStatus(422)->assertJsonPath('code', 'faceid_failed');
    });

    it('refuses a replayed assertion on a new intent (challenge bound to the intent)', function () {
        $auth = registerFaceId($this, $this->token);
        $first = $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5]);
        $assertion = $auth->get($first->json('faceid_options.public_key'));
        at('2026-11-16 07:59'); // first intent expired, unused
        $second = $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5]);
        $this->withToken($this->token)->postJson('/api/attendance/check-in/confirm', ['intent_id' => $second->json('intent_id'), 'assertion' => $assertion])
            ->assertStatus(422)->assertJsonPath('code', 'faceid_failed');
        $this->withToken($this->token)->postJson('/api/attendance/check-in/confirm', ['intent_id' => $first->json('intent_id'), 'assertion' => $assertion])
            ->assertStatus(422);
        expect(Attendance::query()->count())->toBe(0);
    });

    it('returns the existing attendance for a double submit and never accepts the assertion on another day', function () {
        $auth = registerFaceId($this, $this->token);
        $intent = $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5]);
        $body = ['intent_id' => $intent->json('intent_id'), 'assertion' => $auth->get($intent->json('faceid_options.public_key'))];
        $this->withToken($this->token)->postJson('/api/attendance/check-in/confirm', $body)->assertCreated();
        $this->withToken($this->token)->postJson('/api/attendance/check-in/confirm', $body)->assertOk()->assertJsonPath('created', false);
        expect(Attendance::query()->count())->toBe(1)->and(WebauthnCredential::query()->sole()->sign_count)->toBe(1);
    });

    it('refuses a sign count regression (cloned authenticator)', function () {
        $auth = registerFaceId($this, $this->token);
        WebauthnCredential::query()->update(['sign_count' => 10]);
        $intent = $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5]);
        $this->withToken($this->token)->postJson('/api/attendance/check-in/confirm', ['intent_id' => $intent->json('intent_id'), 'assertion' => $auth->get($intent->json('faceid_options.public_key'), ['counter' => 5])])
            ->assertStatus(422)->assertJsonPath('code', 'faceid_failed');
    });

    it('refuses a revoked credential', function () {
        registerFaceId($this, $this->token);
        WebauthnCredential::query()->update(['status' => 'revoked']);
        $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])
            ->assertForbidden()->assertJsonPath('code', 'faceid_not_registered');
    });

    it('refuses a credential revoked between intent and confirmation', function () {
        $auth = registerFaceId($this, $this->token);
        $intent = $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5]);
        WebauthnCredential::query()->update(['status' => 'revoked']);
        $this->withToken($this->token)->postJson('/api/attendance/check-in/confirm', ['intent_id' => $intent->json('intent_id'), 'assertion' => $auth->get($intent->json('faceid_options.public_key'))])
            ->assertStatus(422)->assertJsonPath('code', 'faceid_failed');
    });

    it('refuses a pending credential and a credential of another device', function () {
        registerFaceId($this, $this->token);
        switchUser($this);
        $token2 = deviceToken($this->user, 'device-test-0000000002');
        $this->withToken($token2)->getJson('/api/attendance/today')->assertJsonPath('faceid_state', 'other_device');
        $this->withToken($token2)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])
            ->assertForbidden()->assertJsonPath('code', 'faceid_other_device');
        registerFaceId($this, $token2);
        $this->withToken($token2)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])
            ->assertForbidden()->assertJsonPath('code', 'faceid_pending_approval');
    });

    it('keeps the admin fallback check-in without Face ID (reason required)', function () {
        $admin = User::factory()->admin(Permission::CheckInForEmployee)->create();
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$this->user->id}/check-in", [])->assertStatus(422);
        $this->postJson("/api/admin/users/{$this->user->id}/check-in", ['reason' => 'Phone broken'])->assertCreated()->assertJsonPath('data.faceid_verified', false);
    });

    it('can be switched off by the super admin (setting)', function () {
        withoutFaceId();
        $this->withToken($this->token)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])
            ->assertCreated()->assertJsonPath('faceid_required', false)->assertJsonPath('faceid_options', null);
    });
});
