<?php

use App\Enums\Role;
use App\Mail\SuperAdminLoginCodeMail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

function otherPayload(array $overrides = []): array
{
    return array_merge([
        'email' => 'yaw.owusu@example.com',
        'first_name' => 'Yaw',
        'last_name' => 'Owusu',
        'employee_type' => 'other',
        'employee_type_other' => 'National service person',
        'job_title' => 'Front desk',
    ], $overrides);
}

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    $this->sa = User::factory()->superAdmin()->create(['email' => 'owner@example.com']);
});

it('accepts a sign-up as "other" and keeps the text typed by the person', function () {
    $this->postJson('/api/auth/register', otherPayload(['employee_type_other' => '  National   service person ']))->assertStatus(202);

    $user = User::findByEmail('yaw.owusu@example.com');
    expect($user->employee_type->value)->toBe('other')->and($user->employee_type_other)->toBe('National service person');
});

it('requires a type, and the text when the type is "other"', function (array $overrides, string $field) {
    $this->postJson('/api/auth/register', otherPayload($overrides))->assertUnprocessable()->assertJsonValidationErrors([$field]);
    $this->withToken(deviceToken($this->sa))->postJson('/api/admin/users', otherPayload($overrides))
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect(User::findByEmail('yaw.owusu@example.com'))->toBeNull();
})->with([
    'no type' => [['employee_type' => null], 'employee_type'],
    'unknown type' => [['employee_type' => 'boss'], 'employee_type'],
    'other without text' => [['employee_type_other' => null], 'employee_type_other'],
    'other with blank text' => [['employee_type_other' => '   '], 'employee_type_other'],
    'text too long' => [['employee_type_other' => str_repeat('a', 61)], 'employee_type_other'],
    'link in text' => [['employee_type_other' => 'see evil.example'], 'employee_type_other'],
    'markup in text' => [['employee_type_other' => '<b>vip</b>'], 'employee_type_other'],
]);

it('drops the text when the type is intern or employee', function () {
    $this->postJson('/api/auth/register', otherPayload(['employee_type' => 'intern']))->assertStatus(202);

    $user = User::findByEmail('yaw.owusu@example.com');
    expect($user->employee_type->value)->toBe('intern')->and($user->employee_type_other)->toBeNull();
});

it('lets an admin create an "other" account, shows the text and filters on it', function () {
    User::factory()->create(['email' => 'regular@example.com']);
    $token = deviceToken($this->sa);

    $this->withToken($token)->postJson('/api/admin/users', otherPayload())->assertCreated()
        ->assertJsonPath('data.employee_type', 'other')->assertJsonPath('data.employee_type_other', 'National service person');

    $this->withToken($token)->getJson('/api/admin/users?employee_type=other')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_type_other', 'National service person');
    $this->withToken($token)->getJson('/api/admin/users?employee_type=employee')->assertOk()
        ->assertJsonPath('data.0.employee_type_other', null);
});

it('keeps type and text consistent in the database', function () {
    $user = User::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['employee_type' => 'other'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update(['employee_type_other' => 'x'])))
        ->toThrow(QueryException::class);
});

it('a person promoted to super admin signs in with the emailed one-time code, no longer with a password', function () {
    $boss = User::factory()->create(['email' => 'director@example.com', 'password' => 'OldPassword2026!']);
    $token = deviceToken($this->sa);
    $this->withToken($token)->putJson("/api/admin/users/{$boss->id}/role", ['role' => 'admin'])->assertOk();
    $this->withToken($token)->putJson("/api/admin/users/{$boss->id}/permissions", ['permissions' => ['promote_to_super_admin']])->assertOk();
    $this->withToken($token)->putJson("/api/admin/users/{$boss->id}/role", ['role' => 'super_admin'])->assertOk();
    expect($boss->refresh()->role)->toBe(Role::SuperAdmin);

    $login = ['email' => 'director@example.com', 'device_id' => 'device-director-0000000001'];
    $this->postJson('/api/auth/login', $login + ['password' => 'OldPassword2026!'])->assertStatus(401);

    $this->postJson('/api/auth/super-admin/code', ['email' => 'director@example.com'])->assertStatus(202);
    $code = null;
    Mail::assertQueued(SuperAdminLoginCodeMail::class, function ($m) use (&$code) {
        $code = $m->code;

        return $m->hasTo('director@example.com');
    });
    $this->postJson('/api/auth/login', $login + ['password' => $code])->assertOk()->assertJsonPath('user.role', 'super_admin');
});
