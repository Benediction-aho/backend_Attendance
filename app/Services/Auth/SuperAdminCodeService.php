<?php

namespace App\Services\Auth;

use App\Enums\LogLevel;
use App\Enums\UserStatus;
use App\Mail\SuperAdminLoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\AtomicLimiter;
use App\Services\Codes;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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
        $withinAccount = AtomicLimiter::hit($accountKey, $perHour, 3600);
        $withinIp = AtomicLimiter::hit($ipKey, $perHour * 4, 3600);
        if (! $withinAccount || ! $withinIp) {
            return;
        }

        $user = User::findByEmail($email);
        if ($user !== null && $user->isSuperAdmin() && $this->lockout->hasLock($user)) {
            $this->recovery->send($user);
        }
    }

    /** Keyed lookup of a one-time code (HMAC with the app key): finds the code without trying every hash. */
    public static function lookupHash(User $user, string $code): string
    {
        return hash_hmac('sha256', 'otp|'.$user->id.'|'.$code, (string) config('app.key'));
    }

    /**
     * Sends a new one-time code (P1-05):
     * - limited per (account, IP) and per IP, so a stranger cannot use up the owner's quota;
     * - a new request only cancels earlier codes requested from the same IP, never a code in transit to the owner;
     * - at most `otp_max_active_codes` codes are valid at once (this also bounds the emails sent).
     */
    public function request(string $email, string $ip): void
    {
        $perHour = $this->settings->int('otp_requests_per_hour');
        $accountKey = 'otp:'.LockoutService::accountKey($email).':'.hash('sha256', $ip);
        $ipKey = 'otp-ip:'.$ip;

        $withinAccount = AtomicLimiter::hit($accountKey, $perHour, 3600);
        $withinIp = AtomicLimiter::hit($ipKey, $perHour * 4, 3600);

        $user = User::findByEmail($email);
        if ($user === null || ! $user->isSuperAdmin() || $user->status !== UserStatus::Active) {
            Hash::make('timing-equaliser');

            return;
        }
        if (! $withinAccount || ! $withinIp) {
            $this->logger->security('auth.login_code_throttled', 'One-time code request refused: too many requests', LogLevel::Warning, $user);
            Hash::make('timing-equaliser');

            return;
        }

        $code = Codes::numeric($this->settings->int('otp_length'));
        $minutes = $this->settings->int('otp_ttl_minutes');
        $maxActive = $this->settings->int('otp_max_active_codes');

        $created = DB::transaction(function () use ($user, $code, $minutes, $ip, $maxActive) {
            // Serialise code requests of this account.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $now = Carbon::now();
            $active = LoginCode::query()->where('user_id', $user->id)->where('purpose', LoginCode::SUPER_ADMIN_LOGIN)
                ->whereNull('used_at')->where('expires_at', '>', $now);

            // A new request replaces the earlier codes asked from the same IP only.
            (clone $active)->where('requested_ip', $ip)->update(['used_at' => $now, 'lookup_hash' => null]);
            if ((clone $active)->count() >= $maxActive) {
                return false;
            }

            LoginCode::query()->create([
                'user_id' => $user->id,
                'purpose' => LoginCode::SUPER_ADMIN_LOGIN,
                'code_hash' => Hash::make($code),
                'lookup_hash' => self::lookupHash($user, $code),
                'expires_at' => $now->copy()->addMinutes($minutes),
                'requested_ip' => $ip,
            ]);

            return true;
        });

        if (! $created) {
            $this->logger->security('auth.login_code_throttled', 'One-time code request refused: too many valid codes', LogLevel::Warning, $user);

            return;
        }

        Mail::to($user->email)->queue(new SuperAdminLoginCodeMail($user->first_name, $code, $minutes));
        $this->logger->security('auth.login_code_sent', 'One-time sign-in code emailed', LogLevel::Info, $user);
    }
}
