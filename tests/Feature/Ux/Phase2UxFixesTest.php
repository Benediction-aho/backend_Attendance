<?php

use App\Mail\IdentificationCodeMail;
use App\Mail\RecoveryLinkMail;
use App\Mail\StaffAlertMail;
use App\Mail\SuperAdminLoginCodeMail;
use App\Models\User;
use App\Services\Auth\IdentificationCodeService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

it('accepts an identification code pasted with spaces and does not count it as a failure (UX2-01)', function () {
    $user = User::factory()->create(['email' => 'ama@example.com']);
    app(IdentificationCodeService::class)->issue($user, null, true);
    $code = null;
    Mail::assertQueued(IdentificationCodeMail::class, function ($m) use (&$code) {
        $code = $m->code;

        return true;
    });
    $this->postJson('/api/auth/login', loginPayload(' Ama@example.com ', "  $code \n"))->assertOk();
});

it('accepts a super admin one-time code with spaces (UX2-01)', function () {
    User::factory()->superAdmin()->create(['email' => 'boss@example.com']);
    $this->postJson('/api/auth/super-admin/code', ['email' => ' boss@example.com '])->assertStatus(202);
    $code = null;
    Mail::assertQueued(SuperAdminLoginCodeMail::class, function ($m) use (&$code) {
        $code = $m->code;

        return true;
    });
    $this->postJson('/api/auth/login', loginPayload('boss@example.com', ' '.substr($code, 0, 4).' '.substr($code, 4).' '))->assertOk();
});

it('still compares a chosen password exactly (spaces are allowed in passwords)', function () {
    User::factory()->create(['email' => 'kofi@example.com', 'password' => Hash::make('my pass phrase 12'), 'must_change_password' => false]);
    $this->postJson('/api/auth/login', loginPayload('kofi@example.com', 'mypassphrase12'))->assertStatus(401);
    $this->postJson('/api/auth/login', loginPayload('kofi@example.com', 'my pass phrase 12'))->assertOk();
});

it('sends a recovery link while the super admin is temporarily blocked (UX2-08)', function () {
    User::factory()->superAdmin()->create(['email' => 'boss@example.com']);
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/login', loginPayload('boss@example.com', '0000000'.$i));
    }
    $this->postJson('/api/auth/login', loginPayload('boss@example.com', '00000009'))->assertStatus(429);
    $this->postJson('/api/auth/recovery', ['email' => 'boss@example.com'])->assertStatus(202);
    $token = null;
    Mail::assertQueued(RecoveryLinkMail::class, function ($m) use (&$token) {
        $token = $m->token;

        return true;
    });
    $this->postJson('/api/auth/recovery/confirm', ['token' => $token])->assertOk();
    $this->postJson('/api/auth/super-admin/code', ['email' => 'boss@example.com'])->assertStatus(202);
});

it('sends no recovery link to a super admin who is neither blocked nor locked', function () {
    User::factory()->superAdmin()->create(['email' => 'boss@example.com']);
    $this->postJson('/api/auth/recovery', ['email' => 'boss@example.com'])->assertStatus(202);
    Mail::assertNotQueued(RecoveryLinkMail::class);
});

it('escapes names only in the HTML part of emails (UX2-17)', function () {
    $mail = new StaffAlertMail('New account request', 'Ama <b>Bold</b> & Co asked for an account.', '/admin/users');
    $mail->assertSeeInText('Ama <b>Bold</b> & Co', false);
    $mail->assertDontSeeInText('&lt;b&gt;', false);
    $mail->assertDontSeeInHtml('Ama <b>Bold</b>', false);
});

it('records and shows the super admin last sign-in and hides the employee type of staff (UX2-18)', function () {
    $sa = User::factory()->superAdmin()->create(['email' => 'boss@example.com']);
    $this->postJson('/api/auth/super-admin/code', ['email' => 'boss@example.com']);
    $code = null;
    Mail::assertQueued(SuperAdminLoginCodeMail::class, function ($m) use (&$code) {
        $code = $m->code;

        return true;
    });
    $token = $this->postJson('/api/auth/login', loginPayload('boss@example.com', $code))->assertOk()
        ->assertJsonPath('user.employee_type', null)->json('token');
    expect($sa->fresh()->last_login_at)->not->toBeNull();
    $this->withToken($token)->getJson('/api/me')->assertJsonPath('data.employee_type', null)
        ->assertJson(fn ($j) => $j->whereType('data.last_login_at', 'string')->etc());
    $employee = User::factory()->create();
    $this->withToken($token)->getJson("/api/admin/users/{$employee->id}")->assertJsonPath('data.employee_type', 'employee');
});

it('keeps the generic answer for a pending account (UX2-11, anti-enumeration)', function () {
    $this->postJson('/api/auth/register', ['email' => 'p@example.com', 'first_name' => 'P', 'last_name' => 'Q', 'employee_type' => 'employee', 'job_title' => 'Clerk'])->assertStatus(202);
    $this->postJson('/api/auth/login', loginPayload('p@example.com', 'whatever'))->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
});
