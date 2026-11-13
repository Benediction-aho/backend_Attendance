<?php

use App\Models\ActivityLog;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('is disabled when no token is configured', function () {
    config(['services.scheduler.tick_token' => '']);
    $this->postJson('/api/ops/scheduler-tick')->assertNotFound();
});

it('refuses a missing or wrong token without running the scheduler', function () {
    config(['services.scheduler.tick_token' => str_repeat('a', 48)]);
    Artisan::spy();
    $this->postJson('/api/ops/scheduler-tick')->assertNotFound();
    $this->postJson('/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('b', 48)])->assertNotFound();
    Artisan::shouldNotHaveReceived('call');
});

it('runs the scheduler once with the right token', function () {
    config(['services.scheduler.tick_token' => str_repeat('a', 48)]);
    Artisan::spy();
    $this->postJson('/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('a', 48)])->assertNoContent();
    Artisan::shouldHaveReceived('call')->once()->with('schedule:run');
});

it('REL-09: answers 404 for every other method, like an unknown route', function (string $method) {
    config(['services.scheduler.tick_token' => str_repeat('a', 48)]);
    Artisan::spy();
    $unknown = $this->json($method, '/api/ops/does-not-exist');

    $this->json($method, '/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('a', 48)])
        ->assertNotFound()->assertExactJson($unknown->json());
    Artisan::shouldNotHaveReceived('call');
})->with(['GET', 'PUT', 'PATCH', 'DELETE']);

it('REL-09: answers 404 instead of 429 once the per-minute limit is reached', function () {
    $this->withoutDefer();
    config(['services.scheduler.tick_token' => str_repeat('a', 48)]);
    Artisan::spy();
    for ($i = 0; $i < 15; $i++) {
        $this->postJson('/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('b', 48)])->assertNotFound();
    }
    // Even the right token is refused with a 404 while the limit holds; no 429 is ever returned.
    $this->postJson('/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('a', 48)])->assertNotFound();
    Artisan::shouldNotHaveReceived('call');
});

it('REL-09: is not subject to the general API limiter (no 429 leaks its existence)', function () {
    config(['services.scheduler.tick_token' => '']);
    app(SettingsService::class)->set(['api_requests_per_minute' => 30]);
    for ($i = 0; $i < 40; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.($i % 2 + 1)])->postJson('/api/ops/scheduler-tick')->assertNotFound();
    }
});

it('REL-18: a wrong token does no database work before the answer is sent', function () {
    config(['services.scheduler.tick_token' => str_repeat('a', 48)]);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->postJson('/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('b', 48)])->assertNotFound();

    expect(DB::getQueryLog())->toBe([])
        ->and(ActivityLog::query()->where('event', 'ops.scheduler_tick_refused')->count())->toBe(0);
});

it('REL-18: the refusal is still logged once the response is sent', function () {
    config(['services.scheduler.tick_token' => str_repeat('a', 48)]);
    $this->postJson('/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('b', 48)])->assertNotFound();
    expect(ActivityLog::query()->where('event', 'ops.scheduler_tick_refused')->count())->toBe(0);

    // Deferred callbacks run once the response is sent (withoutDefer runs them inline, as after the response).
    $this->withoutDefer();
    $this->postJson('/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('b', 48)])->assertNotFound();
    expect(ActivityLog::query()->where('event', 'ops.scheduler_tick_refused')->count())->toBe(1);
});
