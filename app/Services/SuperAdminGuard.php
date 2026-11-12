<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * There must always remain at least one active super admin (SPECS section 2).
 */
class SuperAdminGuard
{
    /**
     * Throws 409 when $target is the last active super admin. Call inside the transaction that
     * removes the account (block, delete, demote); the super admin rows are locked to avoid races.
     */
    public function ensureNotLast(User $target): void
    {
        if (! $target->isSuperAdmin()) {
            return;
        }

        $others = DB::table('users')
            ->where('role', 'super_admin')->where('status', 'active')->whereNull('deleted_at')
            ->where('id', '!=', $target->id)
            ->lockForUpdate()
            ->pluck('id');

        if ($others->isEmpty()) {
            throw new ApiError(409, 'At least one active super admin must remain.', 'last_super_admin');
        }
    }
}
