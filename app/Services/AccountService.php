<?php

namespace App\Services;

use App\Enums\EmployeeType;
use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\ApiError;
use App\Mail\SecurityAlertMail;
use App\Models\PermissionRecord;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\Auth\IdentificationCodeService;
use App\Services\Auth\LockoutService;
use App\Services\Auth\SessionRevoker;
use App\Settings\SettingsService;
use App\Support\SignupText;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Account lifecycle: sign-up request, approval, direct creation, block, unlock, delete, role and permissions.
 * Authorization is checked by the controllers (UserPolicy); this class enforces the business invariants.
 */
class AccountService
{
    public function __construct(
        private readonly IdentificationCodeService $codes,
        private readonly ActivityLogger $logger,
        private readonly SuperAdminGuard $guard,
        private readonly LockoutService $lockout,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Public sign-up request. Returns null when the email is already used (the caller answers the same way).
     *
     * @param  array{email: string, first_name: string, last_name: string, employee_type: string, job_title: string}  $data
     */
    public function register(array $data): ?User
    {
        if (User::findByEmail($data['email']) !== null) {
            $this->logger->security('auth.signup_duplicate', 'Sign-up request for an email already in use', LogLevel::Info);

            return null;
        }

        try {
            $user = User::query()->create([
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'employee_type' => EmployeeType::from($data['employee_type']),
                'job_title' => $data['job_title'],
                'role' => Role::Employee,
                'status' => UserStatus::Pending,
            ]);
        } catch (QueryException) {
            return null; // concurrent duplicate (unique index)
        }

        $this->logger->log('user.signup_requested', 'Sign-up request received', user: $user, actor: $user);

        $this->alertApprovers($user);

        return $user;
    }

    /**
     * REL-03: every approver gets an in-app notification; e-mails are bounded whatever the number of requests:
     * the first `signup_alert_emails_per_hour` requests of the hour are e-mailed one by one, the next one triggers a
     * single summary e-mail, the rest are in-app only. Sign-up text is neutralised so it can never become a link.
     */
    private function alertApprovers(User $user): void
    {
        $approvers = User::query()->holdingPermission(Permission::ApproveUsers)->get();
        $perHour = $this->settings->int('signup_alert_emails_per_hour');
        $slot = AtomicLimiter::increment('signup-alert-mail', 3600);

        $name = SignupText::neutralize($user->fullName());
        $jobTitle = SignupText::neutralize((string) $user->job_title);
        Notification::send($approvers, new StaffAlert(
            'signup_request',
            'New account request',
            "{$name} ({$user->email}, {$jobTitle}) wants to create an account.",
            ['user_id' => $user->id],
            withEmail: $slot <= $perHour,
            path: '/admin/users/'.$user->id,
        ));

        if ($slot === $perHour + 1) {
            $this->logger->security('auth.signup_alert_digest', 'Many sign-up requests this hour: further alerts are in-app only', LogLevel::Warning);
            Notification::send($approvers, new StaffAlert(
                'signup_request_digest',
                'Many new account requests',
                "More than {$perHour} account requests were received in the last hour. Further requests this hour are listed in the app only. Please review the pending requests.",
                [],
                path: '/admin/users',
            ));
        }
    }

    /** REL-03: removes sign-up requests nobody answered within `pending_signup_retention_days`. */
    public function purgeStalePending(): int
    {
        $cutoff = Carbon::now()->subDays($this->settings->int('pending_signup_retention_days'));
        $count = 0;
        User::query()->where('status', UserStatus::Pending->value)->where('created_at', '<', $cutoff)
            ->orderBy('id')->each(function (User $user) use (&$count) {
                DB::transaction(function () use ($user, &$count) {
                    $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();
                    if ($locked === null || $locked->status !== UserStatus::Pending) {
                        return;
                    }
                    $locked->status = UserStatus::Deleted;
                    $locked->save();
                    $locked->delete();
                    $count++;
                });
            });
        if ($count > 0) {
            $this->logger->log('user.signup_expired', "Unanswered sign-up requests removed: {$count}", LogType::Action, LogLevel::Info, context: ['count' => $count]);
        }

        return $count;
    }

    public function approve(User $user, User $by): User
    {
        DB::transaction(function () use ($user, $by) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== UserStatus::Pending) {
                throw new ApiError(409, 'This request has already been processed.', 'already_processed');
            }
            $user->status = UserStatus::Active;
            $user->approved_by = $by->id;
            $user->approved_at = Carbon::now();
            $user->save();
            $this->codes->issue($user, $by, true);
        });

        $this->logger->log('user.approved', 'Account approved', user: $user, actor: $by);

        return $user;
    }

    public function reject(User $user, User $by): void
    {
        DB::transaction(function () use ($user, $by) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== UserStatus::Pending) {
                throw new ApiError(409, 'This request has already been processed.', 'already_processed');
            }
            $user->status = UserStatus::Deleted;
            $user->save();
            $user->delete();
            $this->logger->log('user.rejected', 'Sign-up request rejected', LogType::Action, LogLevel::Info, $user, $by);
        });
    }

    /**
     * Direct creation by an authorized admin: active immediately, identification code emailed.
     *
     * @param  array{email: string, first_name: string, last_name: string, employee_type: string, job_title: string, role: string}  $data
     * @param  list<string>  $permissions
     */
    public function create(array $data, User $by, array $permissions = []): User
    {
        if (User::findByEmail($data['email']) !== null) {
            throw new ApiError(409, 'An account with this email already exists.', 'email_taken');
        }

        $user = DB::transaction(function () use ($data, $by, $permissions) {
            $user = User::query()->create([
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'employee_type' => EmployeeType::from($data['employee_type']),
                'job_title' => $data['job_title'],
                'role' => Role::from($data['role']),
                'status' => UserStatus::Active,
            ]);
            $user->approved_by = $by->id;
            $user->approved_at = Carbon::now();
            $user->save();

            if ($user->isAdmin() && $permissions !== []) {
                $this->syncPermissions($user, $permissions, $by, log: false);
            }
            if (! $user->isSuperAdmin()) {
                $this->codes->issue($user, $by, true);
            } else {
                Mail::to($user->email)->queue(new SecurityAlertMail($user->first_name, 'Your super admin account is ready', [
                    'A super admin account was created for you on Assistance Ghana Attendance.',
                    'To sign in, enter your email address: a one-time code will be emailed to you each time.',
                ]));
            }

            return $user;
        });

        $this->logger->log('user.created', 'Account created by an administrator', LogType::Action, LogLevel::Info, $user, $by, ['role' => $user->role->value]);

        return $user;
    }

    public function block(User $user, User $by): void
    {
        DB::transaction(function () use ($user) {
            $this->guard->ensureNotLast($user);
            $user->status = UserStatus::Blocked;
            $user->save();
            SessionRevoker::revokeForAccountState($user, 'blocked');
        });
        $this->logger->security('user.blocked', 'Account blocked', LogLevel::Warning, $user, $by);
    }

    public function unblock(User $user, User $by): void
    {
        if ($user->status !== UserStatus::Blocked) {
            throw new ApiError(409, 'This account is not blocked.', 'not_blocked');
        }
        $user->status = UserStatus::Active;
        $user->save();
        $this->logger->security('user.unblocked', 'Account unblocked', LogLevel::Info, $user, $by);
    }

    public function unlock(User $user, User $by, bool $sendNewCode): void
    {
        if (! $this->lockout->hasLock($user)) {
            throw new ApiError(409, 'This account is not locked.', 'not_locked');
        }
        $this->lockout->unlockAccount($user);
        if ($sendNewCode && ! $user->isSuperAdmin()) {
            $this->codes->issue($user, $by, false);
        }
        $this->logger->security('user.unlocked', 'Sign-in unlocked'.($sendNewCode ? ' with a new identification code' : ''), LogLevel::Warning, $user, $by);
        Mail::to($user->email)->queue(new SecurityAlertMail($user->first_name, 'Your access has been restored', [
            'An administrator restored your access to Assistance Ghana Attendance.',
            $sendNewCode ? 'A new identification code was sent to you in a separate email.' : 'You can sign in again.',
        ]));
    }

    public function regenerateCode(User $user, User $by): void
    {
        if (! in_array($user->status, [UserStatus::Active, UserStatus::Locked], true)) {
            throw new ApiError(409, 'A code can only be sent to an active account.', 'not_active');
        }
        $this->codes->issue($user, $by, false);
    }

    public function delete(User $user, User $by): void
    {
        DB::transaction(function () use ($user) {
            $this->guard->ensureNotLast($user);
            $user->status = UserStatus::Deleted;
            $user->save();
            SessionRevoker::revokeForAccountState($user, 'deleted');
            $user->delete();
        });
        $this->logger->security('user.deleted', 'Account deleted', LogLevel::Warning, $user, $by);
    }

    public function changeRole(User $user, Role $role, User $by): void
    {
        if ($user->role === $role) {
            return;
        }
        if (! $user->isActive()) {
            throw new ApiError(409, 'Only an active account can change role.', 'not_active');
        }
        // REL-08: the "Eligible for promotion to super admin" tier is enforced (setting, on by default).
        if ($role === Role::SuperAdmin && $this->settings->bool('super_admin_promotion_requires_tier')
            && ! ($user->role === Role::Admin && $user->hasPermission(Permission::PromoteToSuperAdmin))) {
            throw new ApiError(422, 'Only an admin marked as eligible for promotion to super admin can become super admin.', 'not_eligible_for_super_admin');
        }
        $old = $user->role;
        DB::transaction(function () use ($user, $role) {
            if ($user->isSuperAdmin()) {
                $this->guard->ensureNotLast($user);
            }
            $user->role = $role;
            if ($role !== Role::Admin) {
                $user->permissionRecords()->detach();
            }
            if ($role === Role::SuperAdmin) {
                // Super admins sign in with one-time codes only.
                $user->password = null;
                $user->must_change_password = false;
                $user->initial_code_expires_at = null;
            }
            $user->save();
            $user->tokens()->delete();
        });

        if ($old === Role::SuperAdmin && $role !== Role::SuperAdmin) {
            $this->codes->issue($user, $by, false);
        }

        $this->logger->security('user.role_changed', "Role changed from {$old->value} to {$role->value}", LogLevel::Warning, $user, $by, ['from' => $old->value, 'to' => $role->value]);
        Mail::to($user->email)->queue(new SecurityAlertMail($user->first_name, 'Your role has changed', [
            'Your role on Assistance Ghana Attendance is now: '.str_replace('_', ' ', $role->value).'.',
            $role === Role::SuperAdmin ? 'From now on, sign in with your email: a one-time code will be emailed to you each time.' : 'Please sign in again.',
        ]));
    }

    /** @param list<string> $keys */
    public function syncPermissions(User $user, array $keys, User $by, bool $log = true): void
    {
        if (! $user->isAdmin()) {
            throw new ApiError(409, 'Permissions apply to admins only.', 'not_admin');
        }
        $before = $user->permissionKeys();
        $ids = PermissionRecord::query()->whereIn('key', $keys)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['granted_by' => $by->id]]);
        $user->permissionRecords()->sync($ids->all());
        $user->flushPermissionCache();

        if ($log) {
            $after = $user->permissionKeys();
            $this->logger->security('user.permissions_changed', 'Admin permissions changed', LogLevel::Warning, $user, $by, [
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ]);
        }
    }
}
