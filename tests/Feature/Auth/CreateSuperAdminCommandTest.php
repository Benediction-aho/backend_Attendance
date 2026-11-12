<?php

use App\Enums\Role;
use App\Mail\SecurityAlertMail;
use App\Mail\SuperAdminLoginCodeMail;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

it('creates the first super admin from flags, without password', function () {
    $this->artisan('attendance:create-super-admin', ['--email' => 'Owner@Example.com', '--first-name' => 'Kofi', '--last-name' => 'Owner', '--no-interaction' => true])
        ->assertSuccessful();

    $user = User::findByEmail('owner@example.com');
    expect($user->role)->toBe(Role::SuperAdmin)->and($user->password)->toBeNull()->and($user->isActive())->toBeTrue()
        ->and(ActivityLog::query()->where('event', 'user.super_admin_installed')->exists())->toBeTrue();
    Mail::assertQueued(SecurityAlertMail::class, fn ($m) => $m->hasTo('owner@example.com'));
});

it('asks interactively for missing values', function () {
    $this->artisan('attendance:create-super-admin')
        ->expectsQuestion('Email address', 'boss@example.com')
        ->expectsQuestion('First name', 'Ama')
        ->expectsQuestion('Last name', 'Boss')
        ->assertSuccessful();

    expect(User::findByEmail('boss@example.com')?->isSuperAdmin())->toBeTrue();
});

it('refuses to run when a super admin already exists', function () {
    User::factory()->superAdmin()->create();

    $this->artisan('attendance:create-super-admin', ['--email' => 'x@example.com', '--first-name' => 'X', '--last-name' => 'Y', '--no-interaction' => true])
        ->assertFailed();
    expect(User::findByEmail('x@example.com'))->toBeNull();
});

it('validates the input', function () {
    $this->artisan('attendance:create-super-admin', ['--email' => 'not-an-email', '--first-name' => 'X', '--last-name' => 'Y', '--no-interaction' => true])->assertFailed();
    $this->artisan('attendance:create-super-admin', ['--no-interaction' => true])->assertFailed();
    expect(User::query()->count())->toBe(0);
});

it('lets the installed super admin sign in with an emailed code', function () {
    $this->artisan('attendance:create-super-admin', ['--email' => 'owner@example.com', '--first-name' => 'K', '--last-name' => 'O', '--no-interaction' => true]);

    $this->postJson('/api/auth/super-admin/code', ['email' => 'owner@example.com'])->assertStatus(202);
    $code = null;
    Mail::assertQueued(SuperAdminLoginCodeMail::class, function ($m) use (&$code) {
        $code = $m->code;

        return true;
    });
    $this->postJson('/api/auth/login', loginPayload('owner@example.com', $code))->assertOk();
});

it('exposes no public setup route', function () {
    foreach (['/api/setup', '/api/install', '/setup', '/api/auth/setup'] as $path) {
        $this->postJson($path, [])->assertNotFound();
    }
});
