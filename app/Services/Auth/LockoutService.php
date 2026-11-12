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

    /** Refuses the attempt (without checking the password) while the bucket is blocked or locked. */
    public function ensureCanAttempt(string $accountKey, string $scope, string $bucketKey, ?User $user): void
    {
        if ($user !== null && $user->status === UserStatus::Locked) {
            throw $this->lockedError();
        }

        $attempt = LoginAttempt::query()->where(['account_key' => $accountKey, 'scope' => $scope, 'bucket_key' => $bucketKey])->first();
        if ($attempt === null) {
            return;
        }
        if ($attempt->locked_at !== null) {
            throw $this->lockedError();
        }
        if ($attempt->blocked_until !== null && $attempt->blocked_until->isFuture()) {
            throw $this->blockedError($attempt->blocked_until);
        }
    }

    /**
     * Records a failed attempt. Returns the error to send when this failure starts a block or a lock.
     */
    public function recordFailure(string $accountKey, string $scope, string $bucketKey, ?User $user): ?ApiError
    {
        $perSeries = $this->settings->int('lockout_failures_per_series');
        $blocks = $this->settings->intList('lockout_block_minutes');
        $now = Carbon::now();

        /** @var array{0: string, 1: LoginAttempt} $outcome */
        $outcome = DB::transaction(function () use ($accountKey, $scope, $bucketKey, $user, $perSeries, $blocks, $now) {
            DB::table('login_attempts')->insertOrIgnore([
                'account_key' => $accountKey, 'scope' => $scope, 'bucket_key' => $bucketKey,
                'user_id' => $user?->id, 'failures' => 0, 'series' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            /** @var LoginAttempt $attempt */
            $attempt = LoginAttempt::query()
                ->where(['account_key' => $accountKey, 'scope' => $scope, 'bucket_key' => $bucketKey])
                ->lockForUpdate()->firstOrFail();

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

            return [$result, $attempt];
        });

        [$result, $attempt] = $outcome;
        $context = ['scope' => $scope, 'series' => $attempt->series, 'account' => substr($accountKey, 0, 12)];

        if ($result === 'failed') {
            $this->logger->security('auth.login_failed', 'Failed sign-in attempt', LogLevel::Warning, $user, context: $context);

            return null;
        }

        if ($result === 'blocked' && $attempt->blocked_until !== null) {
            $this->logger->security('auth.login_blocked', 'Sign-in blocked after repeated failures', LogLevel::Warning, $user, context: $context + ['until' => $attempt->blocked_until->toIso8601String()]);

            return $this->blockedError($attempt->blocked_until);
        }

        $this->logger->security('auth.device_locked', $scope === LoginAttempt::SCOPE_DEVICE ? 'Sign-in locked on a device' : 'Sign-in locked for unknown devices', LogLevel::Critical, $user, context: $context);

        if ($user !== null) {
            $this->onLocked($user, $accountKey);
        }

        return $this->lockedError();
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

    public function hasLock(User $user): bool
    {
        return $user->status === UserStatus::Locked
            || LoginAttempt::query()->where('account_key', self::accountKey($user->email))->whereNotNull('locked_at')->exists();
    }

    private function onLocked(User $user, string $accountKey): void
    {
        $lockedBuckets = LoginAttempt::query()->where('account_key', $accountKey)->whereNotNull('locked_at')->count();
        $wholeAccount = $lockedBuckets >= $this->settings->int('lockout_multi_device_threshold');

        if ($wholeAccount && $user->status === UserStatus::Active) {
            $user->status = UserStatus::Locked;
            $user->save();
            $user->tokens()->delete();
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
