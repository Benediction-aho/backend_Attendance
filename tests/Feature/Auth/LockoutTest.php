<?php

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Mail\SecurityAlertMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

const PHONE = 'ama-phone-000000000001';
const LAPTOP = 'ama-laptop-00000000001';

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-12 08:00', 'Africa/Accra'));
    $this->user = User::factory()->withPassword('Correct-Pass-1')->create(['email' => 'ama@example.com']);
    // The phone is a known device: it already signed in successfully.
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', PHONE))->assertOk();
});

function fail(object $test, string $device, int $times = 1): TestResponse
{
    $response = null;
    for ($i = 0; $i < $times; $i++) {
        $response = $test->guest()->postJson('/api/auth/login', loginPayload('ama@example.com', 'bad-'.$i, $device));
    }

    return $response;
}

it('blocks a device 10 minutes after 5 failures, then 20 minutes, then locks it', function () {
    fail($this, PHONE, 4)->assertStatus(401)->assertJsonPath('message', 'Invalid email or code.');
    fail($this, PHONE)->assertStatus(429)->assertJsonPath('code', 'login_blocked')
        ->assertJsonPath('message', 'Too many failed attempts. Try again in 10 minutes.')
        ->assertJsonPath('retry_after_seconds', 600);

    // During the block even the right password is refused without being checked.
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', PHONE))->assertStatus(429);

    Carbon::setTestNow(Carbon::now()->addMinutes(9));
    fail($this, PHONE)->assertStatus(429)->assertJsonPath('message', 'Too many failed attempts. Try again in 1 minute.');

    Carbon::setTestNow(Carbon::now()->addMinutes(2));
    fail($this, PHONE, 4)->assertStatus(401);
    fail($this, PHONE)->assertStatus(429)->assertJsonPath('retry_after_seconds', 1200);

    Carbon::setTestNow(Carbon::now()->addMinutes(21));
    fail($this, PHONE, 4)->assertStatus(401);
    fail($this, PHONE)->assertStatus(423)->assertJsonPath('code', 'account_locked');

    Carbon::setTestNow(Carbon::now()->addDays(3));
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', PHONE))->assertStatus(423);
});

it('resets the counter after a successful sign-in', function () {
    fail($this, PHONE, 4);
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', PHONE))->assertOk();
    fail($this, PHONE, 4)->assertStatus(401);
});

it('does not let an attacker on other devices block the employee on her own phone', function () {
    exhaustLogins($this, 'ama@example.com', 'attacker-device-000001');
    fail($this, 'attacker-device-000002')->assertStatus(423);

    $this->guest()->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', PHONE))->assertOk();
    expect($this->user->refresh()->status)->toBe(UserStatus::Active);
});

it('makes all unknown devices share one strict bucket (rotating device ids does not help)', function () {
    for ($i = 0; $i < 4; $i++) {
        fail($this, "rotating-device-00000$i")->assertStatus(401);
    }
    fail($this, 'rotating-device-000009')->assertStatus(429);
    // Including a genuinely new device of the owner, while the unknown bucket is blocked.
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', LAPTOP))->assertStatus(429);
});

it('locks the whole account when several device buckets are locked, revokes sessions and alerts', function () {
    $blocker = User::factory()->admin(Permission::BlockUsers)->create();
    $other = User::factory()->admin(Permission::ApproveUsers)->create();
    $sa = User::factory()->superAdmin()->create();
    $token = deviceToken($this->user, 'ama-tablet-00000000001');

    exhaustLogins($this, 'ama@example.com', 'attacker-device-000001');
    expect($this->user->refresh()->status)->toBe(UserStatus::Active);
    exhaustLogins($this, 'ama@example.com', PHONE);

    expect($this->user->refresh()->status)->toBe(UserStatus::Locked)
        ->and(ActivityLog::query()->where('event', 'auth.account_locked')->exists())->toBeTrue();
    $this->bearer($token)->getJson('/api/me')->assertUnauthorized();
    $this->guest()->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', LAPTOP))->assertStatus(423);

    Notification::assertSentTo([$blocker, $sa], StaffAlert::class, fn ($n) => $n->kind === 'account_locked');
    Notification::assertNotSentTo($other, StaffAlert::class);
    Mail::assertQueued(SecurityAlertMail::class, fn ($m) => $m->hasTo('ama@example.com'));
});

it('alerts only super admins when an admin account is locked', function () {
    $admin = User::factory()->admin(Permission::BlockUsers)->withPassword('Correct-Pass-1')->create(['email' => 'adm@example.com']);
    $blocker = User::factory()->admin(Permission::BlockUsers)->create();
    $sa = User::factory()->superAdmin()->create();

    exhaustLogins($this, 'adm@example.com', 'x-device-000000000001');

    Notification::assertSentTo($sa, StaffAlert::class);
    Notification::assertNotSentTo([$blocker, $admin], StaffAlert::class);
});

it('answers unknown emails exactly like real ones', function () {
    $real = [];
    $ghost = [];
    for ($i = 0; $i < 5; $i++) {
        $r = $this->guest()->postJson('/api/auth/login', loginPayload('ama@example.com', 'nope', LAPTOP));
        $g = $this->guest()->postJson('/api/auth/login', loginPayload('ghost@example.com', 'nope', LAPTOP));
        $real[] = [$r->status(), $r->json('message')];
        $ghost[] = [$g->status(), $g->json('message')];
    }
    expect($ghost)->toBe($real)->and($real[4][0])->toBe(429);
});

it('limits failed attempts per IP across accounts', function () {
    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/auth/login', loginPayload("user$i@example.com", 'nope', LAPTOP));
    }
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', PHONE))
        ->assertStatus(429)->assertJsonPath('code', 'ip_limited');
});

it('reads thresholds and durations from settings', function () {
    app(SettingsService::class)->set(['lockout_failures_per_series' => 3, 'lockout_block_minutes' => [1]]);

    fail($this, PHONE, 2)->assertStatus(401);
    fail($this, PHONE)->assertStatus(429)->assertJsonPath('retry_after_seconds', 60);
    Carbon::setTestNow(Carbon::now()->addMinutes(2));
    fail($this, PHONE, 2)->assertStatus(401);
    fail($this, PHONE)->assertStatus(423);
});

it('logs failures, blocks and locks as security events without secrets', function () {
    exhaustLogins($this, 'ama@example.com', PHONE);

    expect(ActivityLog::query()->where('event', 'auth.login_failed')->count())->toBe(12)
        ->and(ActivityLog::query()->where('event', 'auth.login_blocked')->count())->toBe(2)
        ->and(ActivityLog::query()->where('event', 'auth.device_locked')->count())->toBe(1);
    expect(json_encode(ActivityLog::query()->pluck('context')))->not->toContain('wrong-secret');
});

it('refuses a blocked account only after the right password, without leaking it before', function () {
    $this->user->update(['status' => UserStatus::Blocked]);

    fail($this, PHONE)->assertStatus(401);
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', PHONE))
        ->assertStatus(403)->assertJsonPath('code', 'account_blocked');
});

it('refuses an expired identification code', function () {
    $this->user->forceFill(['must_change_password' => true, 'initial_code_expires_at' => Carbon::now()->subMinute()])->save();

    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', PHONE))
        ->assertStatus(401)->assertJsonPath('code', 'code_expired');
});

it('keeps one token per device', function () {
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', PHONE))->assertOk();
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', LAPTOP))->assertOk();

    expect($this->user->tokens()->count())->toBe(2)->and($this->user->devices()->count())->toBe(2);
});

it('requires a well-formed device id', function () {
    $this->postJson('/api/auth/login', ['email' => 'ama@example.com', 'password' => 'Correct-Pass-1'])->assertUnprocessable()->assertJsonValidationErrors('device_id');
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', 'short'))->assertUnprocessable();
});
