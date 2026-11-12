<?php

use App\Enums\UserStatus;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Auth\LockoutService;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-12 08:00', 'Africa/Accra'));
});

it('describes min, max and unit for every setting', function () {
    Sanctum::actingAs(User::factory()->superAdmin()->create());
    $rows = collect($this->getJson('/api/admin/settings')->assertOk()->json('data'))->keyBy('key');
    expect($rows['password_min_length'])->toMatchArray(['type' => 'int', 'min' => 8, 'max' => 64, 'unit' => 'characters'])
        ->and($rows['lockout_block_minutes'])->toHaveKeys(['min', 'max', 'unit', 'item_min', 'item_max'])
        ->and($rows['lockout_block_minutes']['unit'])->toBe('minutes');
    $rows->each(fn ($r) => expect($r)->toHaveKeys(['min', 'max', 'unit']));
});

it('returns password_min_length on GET /me', function () {
    app(SettingsService::class)->set(['password_min_length' => 12]);
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/me')->assertOk()->assertJsonPath('data.password_min_length', 12);
});

it('returns remaining_attempts only for a known device, never for unknown emails or devices', function () {
    User::factory()->withPassword('Correct-Pass-1')->create(['email' => 'ama@example.com']);
    $this->postJson('/api/auth/login', loginPayload('ama@example.com', 'Correct-Pass-1', 'ama-phone-000000000001'))->assertOk();
    $per = app(SettingsService::class)->int('lockout_failures_per_series');

    $this->guest()->postJson('/api/auth/login', loginPayload('ama@example.com', 'bad', 'ama-phone-000000000001'))
        ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials')->assertJsonPath('remaining_attempts', $per - 1);
    $this->guest()->postJson('/api/auth/login', loginPayload('ama@example.com', 'bad', 'ama-phone-000000000001'))
        ->assertJsonPath('remaining_attempts', $per - 2);

    $known = $this->guest()->postJson('/api/auth/login', loginPayload('ama@example.com', 'bad', 'new-device-00000000001'))->assertStatus(401);
    $unknown = $this->guest()->postJson('/api/auth/login', loginPayload('nobody@example.com', 'bad', 'new-device-00000000001'))->assertStatus(401);
    expect(array_keys($known->json()))->toBe(array_keys($unknown->json()))
        ->and($unknown->json())->not->toHaveKey('remaining_attempts');
});

it('answers 401 account_blocked on the next request after a block, lock or deletion', function (string $how) {
    $user = User::factory()->create();
    $token = deviceToken($user);
    $this->withToken($token)->getJson('/api/me')->assertOk();
    $sa = User::factory()->superAdmin()->create();
    match ($how) {
        'block' => app(AccountService::class)->block($user, $sa),
        'delete' => app(AccountService::class)->delete($user, $sa),
        'lock' => (function () use ($user) {
            $user->status = UserStatus::Active;
            app(SettingsService::class)->set(['lockout_multi_device_threshold' => 2]);
            foreach (['dev-a', 'dev-b'] as $b) {
                LoginAttempt::query()->create(['account_key' => LockoutService::accountKey($user->email), 'scope' => 'device', 'bucket_key' => $b, 'failures' => 0, 'series' => 3, 'locked_at' => now()]);
            }
            (new ReflectionMethod(LockoutService::class, 'onLocked'))->invoke(app(LockoutService::class), $user, LockoutService::accountKey($user->email));
        })(),
    };
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/me')->assertStatus(401)->assertJsonPath('code', 'account_blocked');
    $this->withToken('999|not-a-real-token')->getJson('/api/me')->assertStatus(401)->assertJsonMissingPath('code');
})->with(['block', 'lock', 'delete']);
