<?php

use App\Http\Controllers\Ops\SchedulerTickController;
use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use App\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

it('REL-18: a wrong token does no database work before the answer; counting and logging are deferred', function () {
    config(['services.scheduler.tick_token' => str_repeat('a', 48)]);
    $request = Request::create('/api/ops/scheduler-tick', 'POST', server: ['HTTP_X_SCHEDULER_TOKEN' => str_repeat('b', 48)]);
    DB::enableQueryLog();
    DB::flushQueryLog();

    $status = null;
    try {
        app(SchedulerTickController::class)($request, app(ActivityLogger::class));
    } catch (HttpException $e) {
        $status = $e->getStatusCode();
    }

    expect($status)->toBe(404)->and(DB::getQueryLog())->toBe([])
        ->and(app(DeferredCallbackCollection::class)->count())->toBe(1);
});

it('REL-22: wrong tokens are counted and logged after the 404 through the real HTTP kernel', function () {
    config(['services.scheduler.tick_token' => str_repeat('a', 48)]);
    Artisan::spy();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('b', 48)])->assertNotFound();
    }

    expect(ActivityLog::query()->where('event', 'ops.scheduler_tick_refused')->count())->toBe(10);
    $this->postJson('/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('a', 48)])->assertNotFound();
    Artisan::shouldNotHaveReceived('call');

    // Another address is not affected.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
        ->postJson('/api/ops/scheduler-tick', [], ['X-Scheduler-Token' => str_repeat('a', 48)])->assertNoContent();
});
