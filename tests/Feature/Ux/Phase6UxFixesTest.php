<?php

// Phase 6 UX report fixes (UX6-03, 05, 06, 07, 08).

use App\Enums\Permission;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    at('2026-11-18 10:00'); // Wednesday
});

it('UX6-03 splits not working into on_leave, day_off and holiday', function () {
    $c = Carbon::parse('2026-01-01');
    User::factory()->create(['created_at' => $c]); // expected
    $leave = User::factory()->create(['created_at' => $c]);
    Leave::query()->create(['user_id' => $leave->id, 'start_date' => '2026-11-18', 'end_date' => '2026-11-18']);
    $off = User::factory()->create(['created_at' => $c]);
    WorkSchedule::query()->create(['user_id' => $off->id, 'effective_from' => '2026-01-01', 'days_off' => [3], 'remote_days' => []]);
    attend($off, '2026-11-18');
    Sanctum::actingAs(User::factory()->superAdmin()->create());
    $t = $this->getJson('/api/admin/dashboard')->assertOk()->json('data.today');
    expect($t)->toMatchArray(['expected' => 1, 'on_leave' => 1, 'day_off' => 1, 'holiday' => 0, 'not_working' => 2, 'checked_in_not_expected' => 1]);

    Holiday::query()->create(['date' => '2026-11-18', 'name' => 'Holiday']);
    $t = $this->getJson('/api/admin/dashboard')->assertOk()->json('data.today');
    expect($t['holiday'] + $t['day_off'] + $t['on_leave'])->toBe($t['not_working'])->and($t['holiday'])->toBeGreaterThan(0);
});

it('UX6-05 exposes Content-Disposition and Retry-After to the front end', function () {
    $origin = (array) config('cors.allowed_origins');
    $r = $this->withHeaders(['Origin' => $origin[0] ?? 'http://localhost'])->getJson('/api/health');
    expect(strtolower((string) $r->headers->get('Access-Control-Expose-Headers')))->toContain('content-disposition')->toContain('retry-after');
});

it('UX6-06 writes a readable device label in the CSV, never the internal id', function () {
    $sa = User::factory()->superAdmin()->create();
    $emp = User::factory()->create();
    $named = Device::query()->create(['user_id' => $emp->id, 'identifier_hash' => str_repeat('a', 64), 'name' => 'Ama phone', 'is_pwa' => true]);
    $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    $plain = Device::query()->create(['user_id' => $emp->id, 'identifier_hash' => str_repeat('b', 64), 'user_agent' => $ua, 'is_pwa' => true]);
    ActivityLog::query()->create(['type' => 'action', 'level' => 'info', 'event' => 'x.one', 'description' => 'd', 'user_id' => $emp->id, 'device_id' => $named->id]);
    ActivityLog::query()->create(['type' => 'action', 'level' => 'info', 'event' => 'x.two', 'description' => 'd', 'user_id' => $emp->id, 'device_id' => $plain->id]);
    ActivityLog::query()->create(['type' => 'action', 'level' => 'info', 'event' => 'x.three', 'description' => 'd', 'user_id' => $emp->id, 'device_id' => 99999]);
    $csv = $this->bearer(deviceToken($sa))->get('/api/admin/activity-logs/export?user_id='.$emp->id)->assertOk()->streamedContent();
    expect($csv)->toContain('"Ama phone"')->toContain('"Safari on iPhone"')->toContain('"Unknown device"')
        ->and(ActivityLogController::deviceLabel(new ActivityLog(['device_id' => null])))->toBe('');
});

it('UX6-06 tells when the next CSV export is possible', function () {
    app(SettingsService::class)->set(['log_export_per_hour' => 1]);
    $token = deviceToken(User::factory()->superAdmin()->create());
    $this->bearer($token)->get('/api/admin/activity-logs/export')->assertOk();
    $r = $this->bearer($token)->getJson('/api/admin/activity-logs/export')->assertStatus(429)->assertJsonPath('code', 'too_many_exports');
    expect($r->json('retry_after_seconds'))->toBeInt()->toBeGreaterThan(0)
        ->and($r->json('message'))->toContain('Try again in')
        ->and($r->headers->get('Retry-After'))->not->toBeNull();
});

it('UX6-07 uses Monday to Friday for the dashboard week, like the weekly report', function () {
    $emp = User::factory()->create(['created_at' => Carbon::parse('2026-01-01')]);
    Sanctum::actingAs($emp);
    $w = $this->getJson('/api/me/dashboard')->assertOk()->json('data.week');
    expect($w['week_start'])->toBe('2026-11-16')->and($w['week_end'])->toBe('2026-11-20');
    $weekly = $this->getJson('/api/me/reports/weekly')->assertOk()->json('data');
    expect([$weekly['week_start'], $weekly['week_end']])->toBe([$w['week_start'], $w['week_end']]);
    Sanctum::actingAs(User::factory()->superAdmin()->create());
    expect($this->getJson('/api/admin/dashboard')->json('data.week'))->toMatchArray(['week_start' => '2026-11-16', 'week_end' => '2026-11-20']);
});

it('UX6-08 daily reports only list employees who had joined by that date', function () {
    $old = User::factory()->create(['created_at' => Carbon::parse('2026-01-01'), 'approved_at' => Carbon::parse('2026-01-01')]);
    $new = User::factory()->create(['created_at' => Carbon::parse('2026-11-18 09:00'), 'approved_at' => Carbon::parse('2026-11-18 09:00')]);
    Sanctum::actingAs(User::factory()->admin(Permission::ViewReports)->create());
    $ids = collect($this->getJson('/api/admin/reports/daily?date=2026-11-17')->assertOk()->json('data'))->pluck('user.id');
    expect($ids)->toContain($old->id)->not->toContain($new->id);
    $ids = collect($this->getJson('/api/admin/reports/daily?date=2026-11-18')->assertOk()->json('data'))->pluck('user.id');
    expect($ids)->toContain($old->id)->toContain($new->id);
});
