<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ends every device session of an account that was blocked, locked or deleted, and remembers the token
 * hashes for a while so the client can be told why (401 code "account_blocked").
 */
class SessionRevoker
{
    public const REMEMBER_DAYS = 30;

    public static function revokeForAccountState(User $user, string $reason): void
    {
        $now = Carbon::now();
        $rows = $user->tokens()->pluck('token')->map(fn ($hash) => [
            'token_hash' => (string) $hash, 'reason' => $reason, 'expires_at' => $now->copy()->addDays(self::REMEMBER_DAYS), 'created_at' => $now,
        ])->all();
        if ($rows !== []) {
            DB::table('revoked_sessions')->insertOrIgnore($rows);
        }
        DB::table('revoked_sessions')->where('expires_at', '<', $now)->delete();
        $user->tokens()->delete();
    }

    /** True when the bearer token was ended by a block, lock or deletion. */
    public static function wasRevoked(?string $bearer): bool
    {
        if ($bearer === null || $bearer === '' || strlen($bearer) > 200) {
            return false;
        }
        $plain = str_contains($bearer, '|') ? explode('|', $bearer, 2)[1] : $bearer;

        return DB::table('revoked_sessions')->where('token_hash', hash('sha256', $plain))->where('expires_at', '>', Carbon::now())->exists();
    }
}
