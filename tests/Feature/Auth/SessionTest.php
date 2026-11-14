<?php

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

it('rejects requests without a valid bearer token', function () {
    $this->getJson('/api/me')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    $this->bearer('1|not-a-real-token')->getJson('/api/me')->assertUnauthorized();
});

it('cuts access immediately when the account is blocked, locked or deleted', function (string $action) {
    $user = User::factory()->create();
    $token = deviceToken($user);
    $this->bearer($token)->getJson('/api/me')->assertOk();

    match ($action) {
        'blocked' => $user->update(['status' => UserStatus::Blocked]),
        'locked' => $user->update(['status' => UserStatus::Locked]),
        'deleted' => $user->delete(),
    };

    $this->bearer($token)->getJson('/api/me')->assertUnauthorized();
    if ($action !== 'deleted') {
        expect($user->tokens()->count())->toBe(0);
    }
})->with(['blocked', 'locked', 'deleted']);

it('keeps a device session alive while used and expires it after the idle period', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-12 08:00', 'Africa/Accra'));
    $user = User::factory()->create();
    $token = deviceToken($user);

    Carbon::setTestNow(Carbon::now()->addDays(29));
    $this->bearer($token)->getJson('/api/me')->assertOk();

    Carbon::setTestNow(Carbon::now()->addDays(29));
    $this->bearer($token)->getJson('/api/me')->assertOk();

    Carbon::setTestNow(Carbon::now()->addDays(31));
    $this->bearer($token)->getJson('/api/me')->assertUnauthorized();
});

it('signs out the current device only', function () {
    $user = User::factory()->create();
    $a = deviceToken($user, 'device-a-0000000000001');
    $b = deviceToken($user, 'device-b-0000000000001');

    $this->bearer($a)->postJson('/api/auth/logout')->assertOk();
    $this->bearer($a)->getJson('/api/me')->assertUnauthorized();
    $this->bearer($b)->getJson('/api/me')->assertOk();
});

it('returns own profile without secrets', function () {
    $user = User::factory()->create();

    $json = $this->bearer(deviceToken($user))->getJson('/api/me')->assertOk()->json('data');
    expect($json)->toHaveKeys(['id', 'email', 'role', 'phone', 'birth_date', 'must_change_password', 'permissions'])
        ->not->toHaveKeys(['password', 'remember_token', 'code_expires_at']);
});

it('validates the new password', function (string $new, string $expected) {
    $user = User::factory()->withPassword('Old-pass-123')->create();

    $this->bearer(deviceToken($user))->putJson('/api/me/password', [
        'current_password' => 'Old-pass-123', 'new_password' => $new, 'new_password_confirmation' => $new,
    ])->assertUnprocessable()->assertJsonPath('errors.new_password.0', $expected);
})->with([
    ['short1', 'The password must be at least 10 characters.'],
    ['onlyletterslong', 'The password must contain letters and numbers.'],
    ['password123', 'This password is too common. Please choose another one.'],
]);

it('refuses a wrong current password and signs other devices out on success', function () {
    $user = User::factory()->withPassword('Old-pass-123')->create();
    $current = deviceToken($user, 'device-a-0000000000001');
    $other = deviceToken($user, 'device-b-0000000000001');

    $this->bearer($current)->putJson('/api/me/password', ['current_password' => 'nope', 'new_password' => 'Brand-new-42', 'new_password_confirmation' => 'Brand-new-42'])
        ->assertStatus(422)->assertJsonPath('code', 'wrong_password');
    $this->bearer($current)->putJson('/api/me/password', ['current_password' => 'Old-pass-123', 'new_password' => 'Brand-new-42', 'new_password_confirmation' => 'Brand-new-42'])
        ->assertOk();

    $this->bearer($current)->getJson('/api/me')->assertOk();
    $this->bearer($other)->getJson('/api/me')->assertUnauthorized();
});
