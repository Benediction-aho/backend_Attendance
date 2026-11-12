<?php

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/**
 * Signs a user in on a device (real Sanctum token) and returns the Bearer token.
 */
function deviceToken(User $user, string $deviceId = 'device-test-0000000001'): string
{
    $device = Device::query()->firstOrCreate(
        ['user_id' => $user->id, 'identifier_hash' => Device::hashIdentifier($deviceId)],
        ['first_login_at' => now(), 'last_seen_at' => now()],
    );
    $token = $user->createToken('device');
    $token->accessToken->forceFill(['device_id' => $device->id])->save();

    return $token->plainTextToken;
}

/** Default login payload. */
function loginPayload(string $email, string $password, string $deviceId = 'device-test-0000000001'): array
{
    return ['email' => $email, 'password' => $password, 'device_id' => $deviceId, 'device_name' => 'Test phone'];
}

/**
 * Fails sign-in through the three series (5 / 5 / 5), waiting out each block with the test clock.
 */
function exhaustLogins(object $test, string $email, string $deviceId = 'device-test-0000000001', int $series = 3): void
{
    for ($s = 0; $s < $series; $s++) {
        for ($i = 0; $i < 5; $i++) {
            $test->postJson('/api/auth/login', loginPayload($email, 'wrong-secret-'.$i, $deviceId));
        }
        Carbon::setTestNow(Carbon::now()->addMinutes(21));
    }
}

require_once __DIR__.'/Support/attendance.php';
require_once __DIR__.'/Support/webauthn.php';
