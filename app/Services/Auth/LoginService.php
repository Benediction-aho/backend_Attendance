<?php

namespace App\Services\Auth;

use App\Enums\LogLevel;
use App\Enums\UserStatus;
use App\Exceptions\ApiError;
use App\Models\Device;
use App\Models\LoginAttempt;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AtomicLimiter;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sign-in with email + secret, where the secret is the identification code / personal password,
 * or for super admins the one-time code received by email. Issues one Bearer token per device.
 */
class LoginService
{
    public const INVALID = 'Invalid email or code.';

    /** bcrypt (cost 12) of a random value nobody knows. */
    private const DUMMY_HASH_12 = '$2y$12$U587WNrZuIN9qsHmo.7mT.IhlXtRnKEuSAG6dScyHe3Asj3OcqAjS';

    public function __construct(
        private readonly LockoutService $lockout,
        private readonly SettingsService $settings,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @param  array{email: string, password: string, device_id: string, device_name?: string|null, is_pwa?: bool|null}  $input
     * @return array{token: string, user: User}
     */
    public function attempt(array $input, string $ip, ?string $userAgent): array
    {
        $user = User::findByEmail($input['email']);
        $accountKey = LockoutService::accountKey($input['email']);
        $deviceHash = Device::hashIdentifier($input['device_id']);
        $device = $user?->devices()->where('identifier_hash', $deviceHash)->whereNotNull('first_login_at')->first();
        [$scope, $bucket] = $device !== null
            ? [LoginAttempt::SCOPE_DEVICE, $deviceHash]
            : [LoginAttempt::SCOPE_UNKNOWN, LockoutService::UNKNOWN_BUCKET];

        // Per-IP limit (P1-01, P1-10): counted before the secret is checked, and only for unknown devices
        // (and unknown emails), so colleagues on known devices behind the shared office IP are never blocked.
        $ipKey = 'login-ip:'.$ip;
        $ipWindow = $this->settings->int('ip_login_window_minutes') * 60;
        if ($scope === LoginAttempt::SCOPE_UNKNOWN && ! AtomicLimiter::hit($ipKey, $this->settings->int('ip_login_failures_limit'), $ipWindow)) {
            $seconds = AtomicLimiter::availableIn($ipKey);
            throw new ApiError(429, 'Too many attempts from this network. Please try again later.', 'ip_limited', ['retry_after_seconds' => $seconds], ['Retry-After' => (string) $seconds]);
        }

        // The attempt is reserved (counted) before the secret is checked: no check-then-act race.
        $outcome = $this->lockout->reserve($accountKey, $scope, $bucket, $user);

        $code = null;
        if ($user === null) {
            $this->dummyCheck($input['password']);
            $valid = false;
        } else {
            // UX2-01: generated codes (identification code, one-time code) never contain whitespace; a pasted
            // space must not count as a failure. Chosen passwords are compared as typed.
            $secret = ($user->isSuperAdmin() || $user->must_change_password) ? (string) preg_replace('/\s+/u', '', $input['password']) : $input['password'];
            $valid = $this->verifySecret($user, $secret, $code);
        }

        if (! $valid) {
            throw $this->lockout->failed($accountKey, $scope, $bucket, $user, $outcome)
                ?? new ApiError(401, self::INVALID, 'invalid_credentials', $scope === LoginAttempt::SCOPE_DEVICE
                    ? ['remaining_attempts' => $this->lockout->remainingAttempts($accountKey, $scope, $bucket)]
                    : []);
        }
        // A correct secret proves knowledge: the reserved attempt is given back.
        $this->lockout->reset($accountKey, $scope, $bucket);
        if ($scope === LoginAttempt::SCOPE_UNKNOWN) {
            AtomicLimiter::release($ipKey, $ipWindow);
        }
        /** @var User $user */

        // From here the secret is correct: status messages no longer reveal anything to an attacker.
        if ($user->status === UserStatus::Blocked) {
            $this->logger->security('auth.login_refused', 'Sign-in refused: account blocked', LogLevel::Warning, $user);
            throw new ApiError(403, 'Your account has been blocked. Please contact an administrator.', 'account_blocked');
        }
        if ($user->status !== UserStatus::Active) {
            throw new ApiError(401, self::INVALID, 'invalid_credentials');
        }
        if ($user->must_change_password && $user->initial_code_expires_at !== null && $user->initial_code_expires_at->isPast()) {
            $this->logger->security('auth.login_refused', 'Sign-in refused: identification code expired', LogLevel::Warning, $user);
            throw new ApiError(401, 'Your identification code has expired. Please ask an administrator for a new one.', 'code_expired');
        }
        if ($code !== null) {
            $claimed = LoginCode::query()->whereKey($code->id)->whereNull('used_at')->update(['used_at' => Carbon::now(), 'lookup_hash' => null]);
            if ($claimed !== 1) {
                throw new ApiError(401, self::INVALID, 'invalid_credentials');
            }
            // P1-17: once signed in, every other outstanding sign-in code of the account is cancelled.
            LoginCode::query()->where('user_id', $user->id)->where('purpose', LoginCode::SUPER_ADMIN_LOGIN)
                ->whereNull('used_at')->update(['used_at' => Carbon::now(), 'lookup_hash' => null]);
        }

        $token = DB::transaction(function () use ($user, $deviceHash, $input, $ip, $userAgent) {
            $now = Carbon::now();
            $device = Device::query()->firstOrNew(['user_id' => $user->id, 'identifier_hash' => $deviceHash]);
            $device->fill([
                'name' => isset($input['device_name']) ? Str::limit($input['device_name'], 120, '') : $device->name,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 500, '') : null,
                'is_pwa' => (bool) ($input['is_pwa'] ?? false),
                'last_ip' => $ip,
                'last_seen_at' => $now,
            ]);
            $device->first_login_at ??= $now;
            $device->save();

            // One token per device: signing in again on the same device replaces its token.
            $user->tokens()->where('device_id', $device->id)->delete();
            $new = $user->createToken('device');
            $new->accessToken->forceFill(['device_id' => $device->id])->save();

            $user->last_login_at = $now;
            $user->save();

            request()->attributes->set('device_id', $device->id);

            return $new->plainTextToken;
        });

        $this->logger->security('auth.login_succeeded', 'Signed in', LogLevel::Info, $user, $user);

        return ['token' => $token, 'user' => $user];
    }

    private function verifySecret(User $user, string $secret, ?LoginCode &$code): bool
    {
        if ($user->isSuperAdmin()) {
            // Several codes may be valid at once (P1-05); the keyed lookup finds the right one with one bcrypt check.
            $code = LoginCode::query()
                ->where('lookup_hash', SuperAdminCodeService::lookupHash($user, $secret))
                ->where('user_id', $user->id)->where('purpose', LoginCode::SUPER_ADMIN_LOGIN)
                ->whereNull('used_at')->where('expires_at', '>', Carbon::now())
                ->first();
            if ($code === null) {
                $this->dummyCheck($secret);

                return false;
            }

            return Hash::check($secret, $code->code_hash);
        }

        if ($user->password === null) {
            $this->dummyCheck($secret);

            return false;
        }

        return Hash::check($secret, $user->password);
    }

    /**
     * Same hashing cost whether or not the account exists (no timing oracle, P1-02): exactly one bcrypt
     * verification against a precomputed hash, never a Hash::make() on the sign-in path.
     */
    private function dummyCheck(string $secret): void
    {
        Hash::check($secret, self::dummyHash());
    }

    /** bcrypt hash of a random value, at the configured cost (precomputed for the production cost of 12). */
    public static function dummyHash(): string
    {
        $rounds = (int) config('hashing.bcrypt.rounds', 12);
        if ($rounds === 12) {
            return self::DUMMY_HASH_12;
        }

        return Cache::rememberForever('auth:dummy-hash:'.$rounds, fn () => Hash::make(Str::random(40)));
    }
}
