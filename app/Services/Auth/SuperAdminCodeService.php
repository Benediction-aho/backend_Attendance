<?php

namespace App\Services\Auth;

use App\Enums\LogLevel;
use App\Enums\UserStatus;
use App\Mail\SuperAdminLoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Codes;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Passwordless super admin sign-in: a new one-time code by email for every sign-in
 * (hashed, single use, short lifetime, request rate limited). The caller always gets the same answer.
 */
class SuperAdminCodeService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ActivityLogger $logger,
        private readonly LockoutService $lockout,
        private readonly RecoveryService $recovery,
    ) {}

    /** Recovery link request: sent only to a super admin whose sign-in is locked. Same answer otherwise. */
    public function requestRecovery(string $email, string $ip): void
    {
        $perHour = $this->settings->int('otp_requests_per_hour');
        $accountKey = 'recovery:'.LockoutService::accountKey($email);
        $ipKey = 'otp-ip:'.$ip;
        if (RateLimiter::tooManyAttempts($accountKey, $perHour) || RateLimiter::tooManyAttempts($ipKey, $perHour * 4)) {
            return;
        }
        RateLimiter::hit($accountKey, 3600);
        RateLimiter::hit($ipKey, 3600);

        $user = User::findByEmail($email);
        if ($user !== null && $user->isSuperAdmin() && $this->lockout->hasLock($user)) {
            $this->recovery->send($user);
        }
    }

    public function request(string $email, string $ip): void
    {
        $perHour = $this->settings->int('otp_requests_per_hour');
        $accountKey = 'otp:'.LockoutService::accountKey($email);
        $ipKey = 'otp-ip:'.$ip;

        if (RateLimiter::tooManyAttempts($accountKey, $perHour) || RateLimiter::tooManyAttempts($ipKey, $perHour * 4)) {
            return;
        }
        RateLimiter::hit($accountKey, 3600);
        RateLimiter::hit($ipKey, 3600);

        $user = User::findByEmail($email);
        if ($user === null || ! $user->isSuperAdmin() || $user->status !== UserStatus::Active) {
            Hash::make('timing-equaliser');

            return;
        }

        $code = Codes::numeric($this->settings->int('otp_length'));
        $minutes = $this->settings->int('otp_ttl_minutes');

        DB::transaction(function () use ($user, $code, $minutes, $ip) {
            // Only the latest code works.
            LoginCode::query()->where('user_id', $user->id)->where('purpose', LoginCode::SUPER_ADMIN_LOGIN)
                ->whereNull('used_at')->update(['used_at' => Carbon::now()]);
            LoginCode::query()->create([
                'user_id' => $user->id,
                'purpose' => LoginCode::SUPER_ADMIN_LOGIN,
                'code_hash' => Hash::make($code),
                'expires_at' => Carbon::now()->addMinutes($minutes),
                'requested_ip' => $ip,
            ]);
        });

        Mail::to($user->email)->queue(new SuperAdminLoginCodeMail($user->first_name, $code, $minutes));
        $this->logger->security('auth.login_code_sent', 'One-time sign-in code emailed', LogLevel::Info, $user);
    }
}
