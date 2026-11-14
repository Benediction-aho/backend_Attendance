<?php

namespace App\Services\Auth;

use App\Enums\LogLevel;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\ApiError;
use App\Mail\SecurityAlertMail;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\ActivityLogger;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Failed-login lockout (security.md section 1), counted per bucket:
 * - each known device (one that already signed in successfully) has its own bucket;
 * - all unknown devices of an account share one strict bucket (rotating device ids does not help);
 * - buckets exist for unknown emails too, so responses never reveal whether an account exists.
 * Series: N failures → block 10 min, N more → block 20 min, N more → locked (values from settings).
 * When enough buckets of the same account are locked (multi-device attack), the whole account is locked.
 */
class LockoutService
{
    public const UNKNOWN_BUCKET = 'unknown';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly ActivityLogger $logger,
        private readonly RecoveryService $recovery,
    ) {}

    public static function accountKey(string $email): string
    {
        return hash('sha256', 'account|'.User::normalizeEmail($email));
    }

    /**
     * Reserves one attempt BEFORE the secret is checked (security report P1-01), atomically under a row lock:
     * refuses while the bucket is blocked or locked, otherwise counts the attempt as a failure in advance.
     * When this attempt completes a series, the block (or lock) is applied immediately, so parallel requests
     * can never check more than "failures per series" secrets nor skip a block stage.
     * A correct secret then calls reset(); a wrong one calls failed() with the returned outcome.
     *
     * @return 'failed'|'blocked'|'locked' what this attempt means if the secret turns out to be wrong
     */
    public function reserve(string $accountKey, string $scope, string $bucketKey, ?User $user): string
    {
        if ($user !== null && $user->status === UserStatus::Locked) {
            throw $this->lockedError();
        }

        $perSeries = $this->settings->int('lockout_failures_per_series');
        $blocks = $this->settings->intList('lockout_block_minutes');
        $now = Carbon::now();

        /** @var array{0: string, 1: ?ApiError} $outcome */
        $outcome = DB::transaction(function () use ($accountKey, $scope, $bucketKey, $user, $perSeries, $blocks, $now) {
            DB::table('login_attempts')->insertOrIgnore([
                'account_key' => $accountKey, 'scope' => $scope, 'bucket_key' => $bucketKey,
                'user_id' => $user?->id, 'failures' => 0, 'series' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            /** @var LoginAttempt $attempt */
            $attempt = LoginAttempt::query()
                ->where(['account_key' => $accountKey, 'scope' => $scope, 'bucket_key' => $bucketKey])
                ->lockForUpdate()->firstOrFail();

            if ($attempt->locked_at !== null) {
                return ['refused', $this->lockedError()];
            }
            if ($attempt->blocked_until !== null && $attempt->blocked_until->gt($now)) {
                return ['refused', $this->blockedError($attempt->blocked_until)];
            }

            $attempt->failures++;
            $attempt->last_failed_at = $now;
            $attempt->user_id ??= $user?->id;
            $result = 'failed';

            if ($attempt->failures >= $perSeries) {
                $attempt->series++;
                $attempt->failures = 0;
                if ($attempt->series <= count($blocks)) {
                    $attempt->blocked_until = $now->copy()->addMinutes($blocks[$attempt->series - 1]);
                    $result = 'blocked';
                } else {
                    $attempt->blocked_until = null;
                    $attempt->locked_at = $now;
                    $result = 'locked';
                }
            }
            $attempt->save();

            return [$result, null];
        });

        [$result, $error] = $outcome;
        if ($error !== null) {
            throw $error;
        }

        /** @var 'failed'|'blocked'|'locked' $result */
        return $result;
    }

    /**
     * Completes a reserved attempt whose secret was wrong: logs it and, when it started a block or a lock,
     * returns the error to send (and runs the lock side effects). Returns null for a plain failure.
     *
     * @param  'failed'|'blocked'|'locked'  $outcome
     */
    public function failed(string $accountKey, string $scope, string $bucketKey, ?User $user, string $outcome, bool $lockSideEffects = true): ?ApiError
    {
        $attempt = LoginAttempt::query()->where(['account_key' => $accountKey, 'scope' => $scope, 'bucket_key' => $bucketKey])->first();
        $context = ['scope' => $scope, 'series' => $attempt->series ?? 0, 'account' => substr($accountKey, 0, 12)];

        if ($outcome === 'failed') {
            $this->logger->security('auth.login_failed', 'Failed sign-in attempt', LogLevel::Warning, $user, context: $context);

            return null;
        }

        if ($outcome === 'blocked') {
            $until = $attempt->blocked_until ?? Carbon::now()->addMinute();
            $this->logger->security('auth.login_blocked', 'Sign-in blocked after repeated failures', LogLevel::Warning, $user, context: $context + ['until' => $until->toIso8601String()]);

            return $this->blockedError($until);
        }

        $this->logger->security('auth.device_locked', $scope === LoginAttempt::SCOPE_DEVICE ? 'Sign-in locked on a device' : 'Sign-in locked for unknown devices', LogLevel::Critical, $user, context: $context);

        if ($user !== null && $lockSideEffects) {
            $this->onLocked($user, $accountKey);
        }

        return $this->lockedError();
    }

    /**
     * Failures left before the next block in this bucket. Only exposed for a known device (a device that
     * already signed in to the account), so it never reveals whether an email exists.
     */
    public function remainingAttempts(string $accountKey, string $scope, string $bucketKey): int
    {
        $failures = (int) (LoginAttempt::query()->where(['account_key' => $accountKey, 'scope' => $scope, 'bucket_key' => $bucketKey])->value('failures') ?? 0);

        return max(0, $this->settings->int('lockout_failures_per_series') - $failures);
    }

    public function reset(string $accountKey, string $scope, string $bucketKey): void
    {
        LoginAttempt::query()->where(['account_key' => $accountKey, 'scope' => $scope, 'bucket_key' => $bucketKey])->delete();
    }

    /** Clears every bucket of the account and reactivates it if it was locked. */
    public function unlockAccount(User $user): void
    {
        LoginAttempt::query()->where('account_key', self::accountKey($user->email))->delete();
        if ($user->status === UserStatus::Locked) {
            $user->status = UserStatus::Active;
            $user->save();
        }
    }

    /** Locked, or temporarily blocked after a series of failures (UX2-08: recovery link available in both cases). */
    public function hasLockOrBlock(User $user): bool
    {
        return $this->hasLock($user) || LoginAttempt::query()->where('account_key', self::accountKey($user->email))
            ->whereNotNull('blocked_until')->where('blocked_until', '>', Carbon::now())->exists();
    }

    public function hasLock(User $user): bool
    {
        return $user->status === UserStatus::Locked
            || LoginAttempt::query()->where('account_key', self::accountKey($user->email))->whereNotNull('locked_at')->exists();
    }

    private function onLocked(User $user, string $accountKey): void
    {
        $lockedBuckets = LoginAttempt::query()->where('account_key', $accountKey)
            ->whereIn('scope', [LoginAttempt::SCOPE_DEVICE, LoginAttempt::SCOPE_UNKNOWN])->whereNotNull('locked_at')->count();
        $wholeAccount = $lockedBuckets >= $this->settings->int('lockout_multi_device_threshold');

        if ($wholeAccount && $user->status === UserStatus::Active) {
            $user->status = UserStatus::Locked;
            $user->save();
            SessionRevoker::revokeForAccountState($user, 'locked');
            $this->logger->security('auth.account_locked', 'Account locked: failed sign-ins from several devices', LogLevel::Critical, $user);
        }

        $what = $wholeAccount ? 'account' : 'sign-in on one device';
        $recipients = $user->role === Role::Employee
            ? User::query()->holdingPermission(Permission::BlockUsers)->where('id', '!=', $user->id)->get()
            : User::query()->activeSuperAdmins()->where('id', '!=', $user->id)->get();
        Notification::send($recipients, new StaffAlert(
            'account_locked',
            'Account locked',
            "The {$what} of {$user->fullName()} was locked after repeated failed sign-in attempts.",
            ['user_id' => $user->id],
            path: '/admin/users/'.$user->id,
        ));

        Mail::to($user->email)->queue(new SecurityAlertMail($user->first_name, 'Your account was locked', [
            'Your account was locked after too many failed sign-in attempts'.($wholeAccount ? ' from several devices.' : ' from one device.'),
            $user->isSuperAdmin()
                ? 'We have sent you a separate email with a link to restore access.'
                : 'An administrator can restore your access. Your other devices may still work.',
        ]));

        if ($user->isSuperAdmin()) {
            $this->recovery->send($user);
        }
    }

    private function blockedError(Carbon $until): ApiError
    {
        $seconds = max(1, (int) ceil(Carbon::now()->diffInSeconds($until, true)));
        $minutes = (int) ceil($seconds / 60);

        return new ApiError(429, "Too many failed attempts. Try again in {$minutes} minute".($minutes > 1 ? 's' : '').'.', 'login_blocked', ['retry_after_seconds' => $seconds], ['Retry-After' => (string) $seconds]);
    }

    private function lockedError(): ApiError
    {
        return new ApiError(423, 'Sign-in is locked after too many failed attempts. Contact an administrator to restore access.', 'account_locked');
    }
}
