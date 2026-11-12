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
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Sign-in with email + secret, where the secret is the identification code / personal password,
 * or for super admins the one-time code received by email. Issues one Bearer token per device.
 */
class LoginService
{
    public const INVALID = 'Invalid email or code.';

    private static ?string $dummyHash = null;

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
        $ipKey = 'login-ip:'.$ip;
        $ipLimit = $this->settings->int('ip_login_failures_limit');
        if (RateLimiter::tooManyAttempts($ipKey, $ipLimit)) {
            $seconds = RateLimiter::availableIn($ipKey);
            throw new ApiError(429, 'Too many attempts from this network. Please try again later.', 'ip_limited', ['retry_after_seconds' => $seconds], ['Retry-After' => (string) $seconds]);
        }

        $user = User::findByEmail($input['email']);
        $accountKey = LockoutService::accountKey($input['email']);
        $deviceHash = Device::hashIdentifier($input['device_id']);
        $device = $user?->devices()->where('identifier_hash', $deviceHash)->whereNotNull('first_login_at')->first();
        [$scope, $bucket] = $device !== null
            ? [LoginAttempt::SCOPE_DEVICE, $deviceHash]
            : [LoginAttempt::SCOPE_UNKNOWN, LockoutService::UNKNOWN_BUCKET];

        $this->lockout->ensureCanAttempt($accountKey, $scope, $bucket, $user);

        $code = null;
        if ($user === null) {
            $this->dummyCheck($input['password']);
            $valid = false;
        } else {
            $valid = $this->verifySecret($user, $input['password'], $code);
        }

        if (! $valid) {
            RateLimiter::hit($ipKey, $this->settings->int('ip_login_window_minutes') * 60);
            $error = $this->lockout->recordFailure($accountKey, $scope, $bucket, $user);
            throw $error ?? new ApiError(401, self::INVALID, 'invalid_credentials');
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
            $claimed = LoginCode::query()->whereKey($code->id)->whereNull('used_at')->update(['used_at' => Carbon::now()]);
            if ($claimed !== 1) {
                throw new ApiError(401, self::INVALID, 'invalid_credentials');
            }
        }

        $this->lockout->reset($accountKey, $scope, $bucket);

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
            $code = LoginCode::query()
                ->where('user_id', $user->id)->where('purpose', LoginCode::SUPER_ADMIN_LOGIN)
                ->whereNull('used_at')->where('expires_at', '>', Carbon::now())
                ->latest('id')->first();
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

    /** Same hashing cost whether or not the account exists (no timing oracle). */
    private function dummyCheck(string $secret): void
    {
        self::$dummyHash ??= Hash::make(Str::random(32));
        Hash::check($secret, self::$dummyHash);
    }
}
