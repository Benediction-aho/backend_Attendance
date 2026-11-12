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

        $approvers = User::query()->holdingPermission(Permission::ApproveUsers)->get();
        Notification::send($approvers, new StaffAlert(
            'signup_request',
            'New account request',
            "{$user->fullName()} ({$user->email}, {$user->job_title}) wants to create an account.",
            ['user_id' => $user->id],
            path: '/admin/users/'.$user->id,
        ));

        return $user;
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
