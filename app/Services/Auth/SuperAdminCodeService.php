<?php

namespace App\Services\Auth;

use App\Enums\LogLevel;
use App\Enums\UserStatus;
use App\Exceptions\ApiError;
use App\Mail\SuperAdminLoginCodeMail;
use App\Models\Device;
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
        if ($user !== null && $user->isSuperAdmin() && $this->lockout->hasLockOrBlock($user)) {
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
     * - at most `otp_max_active_codes` codes are valid at once (this also bounds the emails sent);
     * - P1-16: when the cap is reached, a request from a device the super admin has already signed in on evicts
     *   the oldest code requested from an unknown device, so a stranger cannot keep the owner from getting a code.
     *   The contention is logged as critical.
     */
    public function request(string $email, string $ip, ?string $deviceId = null): void
    {
        $perHour = $this->settings->int('otp_requests_per_hour');
        $accountKey = 'otp:'.LockoutService::accountKey($email).':'.hash('sha256', $ip);
        $ipKey = 'otp-ip:'.$ip;

        $withinAccount = AtomicLimiter::hit($accountKey, $perHour, 3600);
        $withinIp = AtomicLimiter::hit($ipKey, $perHour * 4, 3600);

        $user = User::findByEmail($email);
        if (! $withinAccount || ! $withinIp) {
            // UX2-07: explicit 429 for this email + IP whether or not the account exists (no enumeration).
            if ($user !== null && $user->isSuperAdmin()) {
                $this->logger->security('auth.login_code_throttled', 'One-time code request refused: too many requests', LogLevel::Warning, $user);
            }
            $seconds = max(AtomicLimiter::availableIn($accountKey), $withinIp ? 0 : AtomicLimiter::availableIn($ipKey));
            throw new ApiError(429, 'You asked for several codes. Use the last code you received, or try again later.', 'too_many_code_requests', ['retry_after_seconds' => $seconds], ['Retry-After' => (string) $seconds]);
        }
        if ($user === null || ! $user->isSuperAdmin() || $user->status !== UserStatus::Active) {
            Hash::make('timing-equaliser');

            return;
        }
        $code = Codes::numeric($this->settings->int('otp_length'));
        $minutes = $this->settings->int('otp_ttl_minutes');
        $maxActive = $this->settings->int('otp_max_active_codes');

        $knownDevice = $deviceId !== null && $deviceId !== '' && $user->devices()
            ->where('identifier_hash', Device::hashIdentifier($deviceId))->whereNotNull('first_login_at')->exists();
        $evicted = false;

        $created = DB::transaction(function () use ($user, $code, $minutes, $ip, $maxActive, $knownDevice, &$evicted) {
            // Serialise code requests of this account.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $now = Carbon::now();
            $active = LoginCode::query()->where('user_id', $user->id)->where('purpose', LoginCode::SUPER_ADMIN_LOGIN)
                ->whereNull('used_at')->where('expires_at', '>', $now);

            // A new request replaces the earlier codes asked from the same IP only.
            (clone $active)->where('requested_ip', $ip)->update(['used_at' => $now, 'lookup_hash' => null]);
            if ((clone $active)->count() >= $maxActive) {
                $oldestUnknown = $knownDevice
                    ? (clone $active)->orderBy('created_at')->orderBy('id')->get()
                        ->first(fn (LoginCode $c) => ! ($c->meta['known_device'] ?? false))
                    : null;
                if ($oldestUnknown === null) {
                    return false;
                }
                LoginCode::query()->whereKey($oldestUnknown->id)->update(['used_at' => $now, 'lookup_hash' => null]);
                $evicted = true;
            }

            LoginCode::query()->create([
                'user_id' => $user->id,
                'purpose' => LoginCode::SUPER_ADMIN_LOGIN,
                'code_hash' => Hash::make($code),
                'lookup_hash' => self::lookupHash($user, $code),
                'expires_at' => $now->copy()->addMinutes($minutes),
                'requested_ip' => $ip,
                'meta' => ['known_device' => $knownDevice],
            ]);

            return true;
        });

        if (! $created) {
            $this->logger->security('auth.login_code_throttled', 'One-time code request refused: too many valid codes', LogLevel::Warning, $user);

            return;
        }

        if ($evicted) {
            $this->logger->security('auth.login_code_contention', 'Too many sign-in codes requested from unknown devices: the oldest was cancelled for a known device', LogLevel::Critical, $user);
        }

        Mail::to($user->email)->queue(new SuperAdminLoginCodeMail($user->first_name, $code, $minutes));
        $this->logger->security('auth.login_code_sent', 'One-time sign-in code emailed', LogLevel::Info, $user);
    }
}
