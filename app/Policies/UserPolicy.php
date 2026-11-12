<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * Account management rules (SPECS section 2, security.md section 1):
 * - super admins can do everything;
 * - admins act only on employees, and only with the matching permission tier;
 * - actions on admins and super admins are reserved to super admins;
 * - nobody blocks, deletes or changes the role of their own account.
 */
class UserPolicy
{
    private const MANAGEMENT = [Permission::CreateUsers, Permission::ApproveUsers, Permission::BlockUsers, Permission::DeleteUsers];

    public function viewAny(User $actor): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }
        foreach (self::MANAGEMENT as $permission) {
            if ($actor->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function view(User $actor, User $target): bool
    {
        if ($actor->id === $target->id || $actor->isSuperAdmin()) {
            return true;
        }

        return $target->role === Role::Employee && $this->viewAny($actor);
    }

    /** Personal data (birth date, address, phone, WhatsApp): the user, super admins, and admins managing the account. */
    public function viewPersonalData(User $actor, User $target): bool
    {
        return $this->view($actor, $target);
    }

    public function create(User $actor, Role $role): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return $role === Role::Employee && $actor->hasPermission(Permission::CreateUsers);
    }

    public function approve(User $actor, User $target): bool
    {
        return $target->status === UserStatus::Pending
            && $this->onEmployee($actor, $target, Permission::ApproveUsers);
    }

    public function block(User $actor, User $target): bool
    {
        return $actor->id !== $target->id && $this->onEmployee($actor, $target, Permission::BlockUsers);
    }

    /** Unlocking a locked account follows the same rule as blocking (security.md section 1). */
    public function unlock(User $actor, User $target): bool
    {
        return $this->block($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->id !== $target->id && $this->onEmployee($actor, $target, Permission::DeleteUsers);
    }

    /** A new identification code: approval tier or block tier on employees; super admin for staff. */
    public function regenerateCode(User $actor, User $target): bool
    {
        if ($actor->id === $target->id || $target->isSuperAdmin()) {
            return false;
        }

        return $this->onEmployee($actor, $target, Permission::ApproveUsers)
            || $this->onEmployee($actor, $target, Permission::BlockUsers);
    }

    public function managePermissions(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin() && $target->isAdmin();
    }

    public function changeRole(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin() && $actor->id !== $target->id;
    }

    private function onEmployee(User $actor, User $target, Permission $permission): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return $target->role === Role::Employee && $actor->hasPermission($permission);
    }
}
