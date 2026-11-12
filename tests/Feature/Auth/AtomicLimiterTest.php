<?php

use App\Services\AtomicLimiter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => Carbon::setTestNow(Carbon::parse('2026-11-12 08:00', 'Africa/Accra')));

it('counts the very first hit of a new key and every hit after it (P1-18)', function () {
    $results = [];
    for ($i = 0; $i < 7; $i++) {
        $results[] = AtomicLimiter::hit('t:new', 5, 3600);
    }
    expect($results)->toBe([true, true, true, true, true, false, false])
        ->and((int) DB::table('rate_limit_counters')->where('key', 't:new')->value('hits'))->toBe(7);
});

it('creates the counter with a single atomic upsert, never a separate add then increment (P1-18)', function () {
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });
    AtomicLimiter::hit('t:upsert', 5, 60);

    expect($queries)->toHaveCount(1)->and($queries[0])->toContain('ON CONFLICT (key) DO UPDATE')->toContain('RETURNING hits');
});

it('starts a new window once the previous one expired, and releases attempts', function () {
    foreach (range(1, 5) as $i) {
        AtomicLimiter::hit('t:win', 5, 60);
    }
    expect(AtomicLimiter::hit('t:win', 5, 60))->toBeFalse()
        ->and(AtomicLimiter::availableIn('t:win'))->toBe(60);

    AtomicLimiter::release('t:win', 60);
    AtomicLimiter::release('t:win', 60);
    expect(AtomicLimiter::hit('t:win', 5, 60))->toBeTrue();

    Carbon::setTestNow(Carbon::now()->addSeconds(61));
    expect(AtomicLimiter::hit('t:win', 5, 60))->toBeTrue()
        ->and((int) DB::table('rate_limit_counters')->where('key', 't:win')->value('hits'))->toBe(1)
        ->and(AtomicLimiter::prune())->toBe(0);
});
