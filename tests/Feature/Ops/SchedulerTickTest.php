<?php

use Illuminate\Support\Facades\Artisan;

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
