<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Hit-first rate limiting (security report P1-01): the attempt is counted atomically BEFORE the guarded work,
 * so parallel requests can never pass a check-then-act gap. The cache store must increment atomically
 * (database or Redis online; the array store in tests runs in one process).
 */
class AtomicLimiter
{
    /** Counts one attempt and returns true while the count stays within $max for the window. */
    public static function hit(string $key, int $max, int $decaySeconds): bool
    {
        return RateLimiter::hit($key, $decaySeconds) <= $max;
    }

    /** Gives back an attempt that turned out not to count (e.g. a successful sign-in). */
    public static function release(string $key, int $decaySeconds): void
    {
        if (RateLimiter::attempts($key) > 0) {
            RateLimiter::decrement($key, $decaySeconds);
        }
    }

    public static function availableIn(string $key): int
    {
        return max(1, RateLimiter::availableIn($key));
    }
}
