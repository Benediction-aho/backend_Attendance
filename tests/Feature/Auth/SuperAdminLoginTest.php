<?php

use App\Mail\RecoveryLinkMail;
use App\Mail\SuperAdminLoginCodeMail;
use App\Models\ActivityLog;
use App\Models\LoginCode;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

function requestCode(object $test, string $email): ?string
{
    $test->postJson('/api/auth/super-admin/code', ['email' => $email])->assertStatus(202);
    $code = null;
    Mail::assertQueued(SuperAdminLoginCodeMail::class, function ($m) use (&$code) {
        $code = $m->code;

        return true;
    });

    return $code;
}

beforeEach(function () {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-12 08:00', 'Africa/Accra'));
    $this->sa = User::factory()->superAdmin()->create(['email' => 'boss@example.com']);
});

it('emails a one-time numeric code, stored hashed with a 10 minute expiry', function () {
    $code = requestCode($this, 'boss@example.com');

    $row = LoginCode::query()->sole();
    expect($code)->toMatch('/^\d{8}$/')
        ->and($row->code_hash)->not->toBe($code)
        ->and(Hash::check($code, $row->code_hash))->toBeTrue()
        ->and($row->expires_at->equalTo(Carbon::now()->addMinutes(10)))->toBeTrue();
});

it('signs the super admin in with the code exactly once', function () {
    $code = requestCode($this, 'boss@example.com');

    $this->postJson('/api/auth/login', loginPayload('boss@example.com', $code))->assertOk()->assertJsonPath('user.role', 'super_admin');
    $this->guest()->postJson('/api/auth/login', loginPayload('boss@example.com', $code))->assertStatus(401);
});

it('refuses an expired code', function () {
    $code = requestCode($this, 'boss@example.com');
    Carbon::setTestNow(Carbon::now()->addMinutes(11));

    $this->postJson('/api/auth/login', loginPayload('boss@example.com', $code))->assertStatus(401)->assertJsonPath('message', 'Invalid email or code.');
});

it('uses the configured expiry', function () {
    app(SettingsService::class)->set(['otp_ttl_minutes' => 3]);
    $code = requestCode($this, 'boss@example.com');
    Carbon::setTestNow(Carbon::now()->addMinutes(4));

    $this->postJson('/api/auth/login', loginPayload('boss@example.com', $code))->assertStatus(401);
});

it('invalidates the previous code when a new one is requested', function () {
    $first = requestCode($this, 'boss@example.com');
    Mail::fake();
    $second = requestCode($this, 'boss@example.com');

    $this->postJson('/api/auth/login', loginPayload('boss@example.com', $first))->assertStatus(401);
    $this->postJson('/api/auth/login', loginPayload('boss@example.com', $second))->assertOk();
});

it('answers the same and sends nothing for unknown emails, employees and admins', function () {
    User::factory()->create(['email' => 'emp@example.com']);
    User::factory()->admin()->create(['email' => 'adm@example.com']);

    foreach (['nobody@example.com', 'emp@example.com', 'adm@example.com'] as $email) {
        $this->postJson('/api/auth/super-admin/code', ['email' => $email])->assertStatus(202)
            ->assertJsonPath('message', 'If this email belongs to a super admin account, a sign-in code has been sent.');
    }
    Mail::assertNothingQueued();
    expect(LoginCode::query()->count())->toBe(0);
});

it('rate limits code requests per account', function () {
    for ($i = 0; $i < 7; $i++) {
        $this->postJson('/api/auth/super-admin/code', ['email' => 'boss@example.com'])->assertStatus(202);
    }
    Mail::assertQueued(SuperAdminLoginCodeMail::class, 5);
});

it('lets a stranger neither use up the owner quota nor cancel the code in transit (P1-05)', function () {
    $code = requestCode($this, 'boss@example.com'); // the owner, from 127.0.0.1

    // A stranger from another network floods requests for the same email.
    for ($i = 0; $i < 10; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/auth/super-admin/code', ['email' => 'boss@example.com'])->assertStatus(202);
    }
    expect(ActivityLog::query()->where('event', 'auth.login_code_throttled')->exists())->toBeTrue();

    // The owner's code still works, and the owner can still ask for a new one.
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
        ->postJson('/api/auth/login', loginPayload('boss@example.com', $code))->assertOk();
    Mail::fake();
    $again = requestCode($this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']), 'boss@example.com');
    expect($again)->not->toBeNull();
});

it('keeps at most the configured number of codes valid at once, which also bounds the emails (P1-05)', function () {
    foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/auth/super-admin/code', ['email' => 'boss@example.com'])->assertStatus(202);
    }
    Mail::assertQueued(SuperAdminLoginCodeMail::class, 3);
    expect(LoginCode::query()->whereNull('used_at')->count())->toBe(3);

    // Any of the valid codes signs in (one keyed lookup, one bcrypt check).
    $codes = [];
    Mail::assertQueued(SuperAdminLoginCodeMail::class, function ($m) use (&$codes) {
        $codes[] = $m->code;

        return true;
    });
    $this->postJson('/api/auth/login', loginPayload('boss@example.com', $codes[0]))->assertOk();
});

it('never accepts a password for a super admin', function () {
    $this->sa->password = Hash::make('Password-123');
    $this->sa->save();

    $this->postJson('/api/auth/login', loginPayload('boss@example.com', 'Password-123'))->assertStatus(401);
});

it('has no password to change', function () {
    $this->bearer(deviceToken($this->sa))->putJson('/api/me/password', ['current_password' => 'x', 'new_password' => 'Abcdefgh123', 'new_password_confirmation' => 'Abcdefgh123'])
        ->assertStatus(422)->assertJsonPath('code', 'no_password');
});

it('sends a recovery link when locked and restores access once', function () {
    exhaustLogins($this, 'boss@example.com');
    $token = null;
    Mail::assertQueued(RecoveryLinkMail::class, function ($m) use (&$token) {
        $token = $m->token;

        return $m->hasTo('boss@example.com');
    });
    $this->postJson('/api/auth/login', loginPayload('boss@example.com', '00000000'))->assertStatus(423);

    $this->postJson('/api/auth/recovery/confirm', ['token' => $token])->assertOk();
    $this->postJson('/api/auth/recovery/confirm', ['token' => $token])->assertStatus(422)->assertJsonPath('code', 'invalid_link');

    Mail::fake();
    $code = requestCode($this, 'boss@example.com');
    $this->postJson('/api/auth/login', loginPayload('boss@example.com', $code))->assertOk();
});

it('lets a locked super admin request a new recovery link, which expires', function () {
    exhaustLogins($this, 'boss@example.com');
    Mail::fake();
    $this->postJson('/api/auth/recovery', ['email' => 'boss@example.com'])->assertStatus(202);
    $token = null;
    Mail::assertQueued(RecoveryLinkMail::class, function ($m) use (&$token) {
        $token = $m->token;

        return true;
    });

    Carbon::setTestNow(Carbon::now()->addMinutes(31));
    $this->postJson('/api/auth/recovery/confirm', ['token' => $token])->assertStatus(422);
});

it('does not send a recovery link to an account that is not locked or not a super admin', function () {
    User::factory()->create(['email' => 'emp@example.com']);
    $this->postJson('/api/auth/recovery', ['email' => 'boss@example.com'])->assertStatus(202);
    $this->postJson('/api/auth/recovery', ['email' => 'emp@example.com'])->assertStatus(202);
    $this->postJson('/api/auth/recovery', ['email' => 'ghost@example.com'])->assertStatus(202);

    Mail::assertNotQueued(RecoveryLinkMail::class);
});

it('rejects forged recovery tokens', function () {
    $this->postJson('/api/auth/recovery/confirm', ['token' => str_repeat('a', 64)])->assertStatus(422);
});
