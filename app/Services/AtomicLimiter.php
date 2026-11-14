<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hit-first rate limiting (security report P1-01, P1-18): the attempt is counted BEFORE the guarded work with one
 * atomic `INSERT ... ON CONFLICT DO UPDATE ... RETURNING`, so parallel requests can neither pass a check-then-act
 * gap nor lose the first hit while the key is being created. Time comes from the server clock (Carbon).
 */
class AtomicLimiter
{
    /** Counts one attempt and returns true while the count stays within $max for the window. */
    public static function hit(string $key, int $max, int $decaySeconds): bool
    {
        $hits = self::increment($key, $decaySeconds);

        return $hits >= 1 && $hits <= $max;
    }

    /** Counts one attempt atomically and returns the number of attempts in the current window (this one included). */
    public static function increment(string $key, int $decaySeconds): int
    {
        $now = Carbon::now();
        $hits = DB::scalar(<<<'SQL'
INSERT INTO rate_limit_counters (key, hits, expires_at) VALUES (?, 1, ?)
ON CONFLICT (key) DO UPDATE SET
    hits = CASE WHEN rate_limit_counters.expires_at <= ? THEN 1 ELSE rate_limit_counters.hits + 1 END,
    expires_at = CASE WHEN rate_limit_counters.expires_at <= ? THEN EXCLUDED.expires_at ELSE rate_limit_counters.expires_at END
RETURNING hits
SQL, [self::key($key), $now->copy()->addSeconds($decaySeconds), $now, $now]);

        return is_numeric($hits) ? (int) $hits : 0;
    }

    /** Gives back an attempt that turned out not to count (e.g. a successful sign-in). */
    public static function release(string $key, int $decaySeconds): void
    {
        DB::table('rate_limit_counters')->where('key', self::key($key))->where('hits', '>', 0)
            ->where('expires_at', '>', Carbon::now())->decrement('hits');
    }

    public static function availableIn(string $key): int
    {
        $expires = DB::table('rate_limit_counters')->where('key', self::key($key))->value('expires_at');

        return max(1, $expires === null ? 1 : (int) ceil(Carbon::now()->diffInSeconds(Carbon::parse((string) $expires), false)));
    }

    /** Deletes expired counters (called by the daily purge). */
    public static function prune(): int
    {
        return DB::table('rate_limit_counters')->where('expires_at', '<=', Carbon::now())->delete();
    }

    private static function key(string $key): string
    {
        return strlen($key) <= 255 ? $key : 'sha256:'.hash('sha256', $key);
    }
}
