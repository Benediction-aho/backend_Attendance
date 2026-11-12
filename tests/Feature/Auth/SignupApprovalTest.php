<?php

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Mail\IdentificationCodeMail;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\StaffAlert;
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

it('rate limits sign-up requests per IP', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/register', signupPayload(['email' => "u$i@example.com"]))->assertStatus(202);
    }
    $this->postJson('/api/auth/register', signupPayload(['email' => 'u9@example.com']))->assertStatus(429);
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
