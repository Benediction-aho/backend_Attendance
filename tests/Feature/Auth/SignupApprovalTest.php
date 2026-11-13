<?php

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Mail\IdentificationCodeMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\AtomicLimiter;
use App\Settings\SettingsService;
use App\Support\SignupText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

function signupPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'Ama.Mensah@Example.com',
        'first_name' => 'Ama',
        'last_name' => 'Mensah',
        'employee_type' => 'intern',
        'job_title' => 'Sales Intern',
    ], $overrides);
}

it('creates a pending account without password and notifies approvers only', function () {
    Notification::fake();
    $sa = User::factory()->superAdmin()->create();
    $approver = User::factory()->admin(Permission::ApproveUsers)->create();
    $otherAdmin = User::factory()->admin(Permission::BlockUsers)->create();
    $employee = User::factory()->create();

    $this->postJson('/api/auth/register', signupPayload())->assertStatus(202);

    $user = User::findByEmail('ama.mensah@example.com');
    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('ama.mensah@example.com')
        ->and($user->status)->toBe(UserStatus::Pending)
        ->and($user->password)->toBeNull()
        ->and($user->employee_type->value)->toBe('intern');

    Notification::assertSentTo([$sa, $approver], StaffAlert::class, fn ($n, $channels) => $n->kind === 'signup_request' && $channels === ['database', 'mail']);
    Notification::assertNotSentTo([$otherAdmin, $employee], StaffAlert::class);
});

it('stores the sign-up notification in the in-app centre', function () {
    $sa = User::factory()->superAdmin()->create();
    $this->postJson('/api/auth/register', signupPayload())->assertStatus(202);

    $this->bearer(deviceToken($sa))->getJson('/api/notifications')
        ->assertOk()->assertJsonPath('meta.unread_count', 1)->assertJsonPath('data.0.data.kind', 'signup_request');
});

it('answers identically for an email already in use and creates nothing', function () {
    User::factory()->create(['email' => 'ama.mensah@example.com']);

    $this->postJson('/api/auth/register', signupPayload())->assertStatus(202)
        ->assertJsonPath('message', 'Your request has been sent. You will receive an email with your identification code once an administrator approves it.');
    expect(User::query()->count())->toBe(1);
});

it('validates the sign-up form', function (array $override, string $field) {
    $this->postJson('/api/auth/register', signupPayload($override))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['email' => 'not-an-email'], 'email'],
    [['first_name' => ''], 'first_name'],
    [['employee_type' => 'boss'], 'employee_type'],
    [['job_title' => ''], 'job_title'],
]);

it('lets a whole office register from one IP, then rate limits with retry_after (UX2-02)', function () {
    for ($i = 0; $i < 50; $i++) {
        $this->postJson('/api/auth/register', signupPayload(['email' => "u$i@example.com"]))->assertStatus(202);
    }
    $this->postJson('/api/auth/register', signupPayload(['email' => 'u99@example.com']))->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After')
        ->assertJson(fn ($json) => $json->where('retry_after_seconds', fn ($v) => $v > 0 && $v <= 3600)->etc());
});

it('rate limits sign-up requests per email (UX2-02)', function () {
    for ($i = 0; $i < 3; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.$i"])->postJson('/api/auth/register', signupPayload(['email' => 'Same@example.com']))->assertStatus(202);
    }
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->postJson('/api/auth/register', signupPayload(['email' => 'same@example.com']))->assertStatus(429)->assertHeader('Retry-After');
});

it('counts sign-up requests before doing any work, so bursts cannot exceed the limit (P1-01)', function () {
    // Simulates 5 requests already in flight from this IP (counted, not finished yet).
    for ($i = 0; $i < 50; $i++) {
        AtomicLimiter::hit('signup:127.0.0.1', 50, 3600);
    }
    $this->postJson('/api/auth/register', signupPayload())->assertStatus(429);
    expect(User::query()->where('email', 'ama.mensah@example.com')->exists())->toBeFalse();
});

it('lets a pending user not sign in', function () {
    $this->postJson('/api/auth/register', signupPayload())->assertStatus(202);

    $this->postJson('/api/auth/login', loginPayload('ama.mensah@example.com', 'anything'))
        ->assertStatus(401)->assertJsonPath('message', 'Invalid email or code.');
});

it('approves a request: unique code hashed, emailed, expiry from settings, logged', function () {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-12 09:00', 'Africa/Accra'));
    $approver = User::factory()->admin(Permission::ApproveUsers)->create();
    $pending = User::factory()->pending()->create();

    $this->bearer(deviceToken($approver))->postJson("/api/admin/users/{$pending->id}/approve")
        ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonMissingPath('data.password');

    $pending->refresh();
    $sent = null;
    Mail::assertQueued(IdentificationCodeMail::class, function (IdentificationCodeMail $m) use ($pending, &$sent) {
        $sent = $m->code;

        return $m->hasTo($pending->email) && $m->isNewAccount;
    });
    expect($sent)->toMatch('/^[A-HJ-NP-Z2-9]{10}$/')
        ->and($pending->password)->not->toBe($sent)
        ->and(Hash::check($sent, $pending->password))->toBeTrue()
        ->and($pending->must_change_password)->toBeTrue()
        ->and($pending->initial_code_expires_at->equalTo(Carbon::now()->addHours(48)))->toBeTrue()
        ->and($pending->approved_by)->toBe($approver->id)
        ->and(ActivityLog::query()->where('event', 'user.approved')->exists())->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'user.code_issued')->first()->context)->not->toHaveKey('code');
});

it('refuses approval without the permission, on non-pending accounts, or twice', function () {
    $pending = User::factory()->pending()->create();
    $noPerm = User::factory()->admin(Permission::BlockUsers)->create();
    $employee = User::factory()->create();
    $sa = User::factory()->superAdmin()->create();

    $this->bearer(deviceToken($noPerm))->postJson("/api/admin/users/{$pending->id}/approve")->assertForbidden();
    $this->bearer(deviceToken($employee, 'device-employee-000001'))->postJson("/api/admin/users/{$pending->id}/approve")->assertForbidden();
    $this->guest()->postJson("/api/admin/users/{$pending->id}/approve")->assertUnauthorized();

    $token = deviceToken($sa, 'device-sa-0000000000001');
    $this->bearer($token)->postJson("/api/admin/users/{$pending->id}/approve")->assertOk();
    $this->bearer($token)->postJson("/api/admin/users/{$pending->id}/approve")->assertForbidden();
});

it('rejects a request: account removed, email free again', function () {
    $sa = User::factory()->superAdmin()->create();
    $pending = User::factory()->pending()->create(['email' => 'kojo@example.com']);

    $this->bearer(deviceToken($sa))->postJson("/api/admin/users/{$pending->id}/reject")->assertOk();

    expect(User::findByEmail('kojo@example.com'))->toBeNull()
        ->and(User::withTrashed()->find($pending->id)->status)->toBe(UserStatus::Deleted);
    $this->postJson('/api/auth/register', signupPayload(['email' => 'kojo@example.com']))->assertStatus(202);
    expect(User::findByEmail('kojo@example.com'))->not->toBeNull();
});

it('runs the full journey: sign-up, approval, login with code, forced change, then free access', function () {
    Mail::fake();
    $sa = User::factory()->superAdmin()->create();
    $this->postJson('/api/auth/register', signupPayload())->assertStatus(202);
    $user = User::findByEmail('ama.mensah@example.com');
    $this->bearer(deviceToken($sa))->postJson("/api/admin/users/{$user->id}/approve")->assertOk();

    $code = null;
    Mail::assertQueued(IdentificationCodeMail::class, function ($m) use (&$code) {
        $code = $m->code;

        return true;
    });

    $login = $this->guest()->postJson('/api/auth/login', loginPayload('AMA.mensah@example.com ', $code, 'phone-of-ama-00000001'))
        ->assertOk()->assertJsonPath('must_change_password', true)->assertJsonPath('token_type', 'Bearer');
    $token = $login->json('token');

    $this->bearer($token)->getJson('/api/notifications')->assertForbidden()->assertJsonPath('code', 'password_change_required');
    $this->bearer($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.must_change_password', true);

    $this->bearer($token)->putJson('/api/me/password', [
        'current_password' => $code, 'new_password' => 'Kente-2026-gold', 'new_password_confirmation' => 'Kente-2026-gold',
    ])->assertOk();

    $this->bearer($token)->getJson('/api/notifications')->assertOk();
    $this->guest()->postJson('/api/auth/login', loginPayload('ama.mensah@example.com', $code, 'phone-of-ama-00000001'))->assertStatus(401);
    $this->postJson('/api/auth/login', loginPayload('ama.mensah@example.com', 'Kente-2026-gold', 'phone-of-ama-00000001'))
        ->assertOk()->assertJsonPath('must_change_password', false);
});

it('REL-03: caps sign-ups globally per hour, whatever the source IP', function () {
    app(SettingsService::class)->set(['signup_requests_global_per_hour' => 3]);
    for ($i = 1; $i <= 3; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
            ->postJson('/api/auth/register', signupPayload(['email' => "p{$i}@example.com"]))->assertStatus(202);
    }
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->postJson('/api/auth/register', signupPayload(['email' => 'p4@example.com']))->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    expect(User::query()->where('status', 'pending')->count())->toBe(3);

    Carbon::setTestNow(Carbon::now()->addMinutes(61));
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->postJson('/api/auth/register', signupPayload(['email' => 'p4@example.com']))->assertStatus(202);
});

it('REL-03: e-mails approvers for the first requests of the hour, then one summary, then in-app only', function () {
    Notification::fake();
    app(SettingsService::class)->set(['signup_alert_emails_per_hour' => 2]);
    $approver = User::factory()->admin(Permission::ApproveUsers)->create();

    for ($i = 1; $i <= 6; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$i])
            ->postJson('/api/auth/register', signupPayload(['email' => "p{$i}@example.com"]))->assertStatus(202);
    }

    $mailed = 0;
    $inAppOnly = 0;
    Notification::assertSentTo($approver, StaffAlert::class, function ($n, $channels) use (&$mailed, &$inAppOnly) {
        if ($n->kind === 'signup_request') {
            in_array('mail', $channels, true) ? $mailed++ : $inAppOnly++;
        }

        return true;
    });
    expect($mailed)->toBe(2)->and($inAppOnly)->toBe(4);
    Notification::assertSentToTimes($approver, StaffAlert::class, 7);
    Notification::assertSentTo($approver, StaffAlert::class, fn ($n, $channels) => $n->kind === 'signup_request_digest' && in_array('mail', $channels, true));
});

it('REL-03: refuses links and markup in names and job title', function (array $override, string $field) {
    $this->postJson('/api/auth/register', signupPayload($override))->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect(User::query()->count())->toBe(0);
})->with([
    'markdown link' => [['first_name' => '[URGENT: verify your account](https://evil.example/login)'], 'first_name'],
    'url in job title' => [['job_title' => 'Your password expires, sign in at https://evil.example'], 'job_title'],
    'bare domain' => [['job_title' => 'Visit evil.example now'], 'job_title'],
    'www' => [['last_name' => 'www.example'], 'last_name'],
    'www in title' => [['job_title' => 'Officer www.example'], 'job_title'],
    'domain in name' => [['last_name' => 'Mensah.evil'], 'last_name'],
    'email in job title' => [['job_title' => 'write to x@evil.example'], 'job_title'],
    'html' => [['job_title' => '<a href=x>Sales</a>'], 'job_title'],
    'digits in name' => [['first_name' => 'Ama2'], 'first_name'],
]);

it('REL-03: accepts real names and job titles', function () {
    $this->postJson('/api/auth/register', signupPayload([
        'first_name' => "Kwame-Akwasi O'Neil", 'last_name' => 'J. Ntiamoah Ébo', 'job_title' => 'Sales & Marketing Officer (Field), Grade 2',
    ]))->assertStatus(202);
});

it('REL-03: neutralises any link-like text before it reaches an e-mail', function () {
    expect(SignupText::neutralize('see https://evil.example/login or www.evil.example [x](y) a@b.c'))
        ->not->toContain('://')->not->toContain('www.')->not->toContain('evil.example')->not->toContain('[')->not->toContain('@');
});

it('REL-03: removes sign-up requests left unanswered beyond the configured retention', function () {
    Notification::fake();
    app(SettingsService::class)->set(['pending_signup_retention_days' => 30]);
    $old = User::factory()->create(['status' => UserStatus::Pending, 'created_at' => Carbon::now()->subDays(31)]);
    $recent = User::factory()->create(['status' => UserStatus::Pending, 'created_at' => Carbon::now()->subDays(29)]);
    $activeOld = User::factory()->create(['created_at' => Carbon::now()->subDays(400)]);

    $this->artisan('attendance:purge-logs')->assertSuccessful();
    $this->artisan('attendance:purge-logs')->assertSuccessful();

    expect(User::query()->find($old->id))->toBeNull()
        ->and(User::withTrashed()->find($old->id)->status)->toBe(UserStatus::Deleted)
        ->and(User::query()->find($recent->id))->not->toBeNull()
        ->and(User::query()->find($activeOld->id))->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'user.signup_expired')->count())->toBe(1);
    // The email is free again.
    $this->postJson('/api/auth/register', signupPayload(['email' => $old->email]))->assertStatus(202);
});
