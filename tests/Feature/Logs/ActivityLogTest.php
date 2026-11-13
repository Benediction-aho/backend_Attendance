<?php

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\ScheduledRun;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ScheduledRunner;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-12 10:00', 'Africa/Accra'));
    $this->sa = User::factory()->superAdmin()->create();
    $this->emp = User::factory()->create();
    $logger = app(ActivityLogger::class);
    $logger->log('profile.updated', 'Profile updated', user: $this->emp, actor: $this->emp);
    $logger->security('auth.login_failed', 'Failed sign-in attempt', LogLevel::Warning, $this->emp);
    Carbon::setTestNow(Carbon::parse('2026-11-10 10:00', 'Africa/Accra'));
    $logger->log('scheduled.x', 'Done', LogType::Automatic);
    Carbon::setTestNow(Carbon::parse('2026-11-12 10:00', 'Africa/Accra'));
});

it('lets the super admin read logs, newest first, paginated', function () {
    $this->bearer(deviceToken($this->sa))->getJson('/api/admin/activity-logs')->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.event', 'auth.login_failed')
        ->assertJsonPath('data.0.user.id', $this->emp->id);
});

it('filters by user, type, level, event and date', function (string $query, int $count) {
    $q = str_replace('{emp}', (string) $this->emp->id, $query);
    $this->bearer(deviceToken($this->sa))->getJson('/api/admin/activity-logs?'.$q)->assertOk()->assertJsonPath('meta.total', $count);
})->with([
    ['user_id={emp}', 2],
    ['type=security', 1],
    ['level=warning', 1],
    ['event=profile', 1],
    ['type=automatic', 1],
    ['from=2026-11-11', 2],
    ['from=2026-11-10&to=2026-11-10', 1],
]);

it('validates filters', function () {
    $this->bearer(deviceToken($this->sa))->getJson('/api/admin/activity-logs?type=nope')->assertUnprocessable();
    $this->bearer(deviceToken($this->sa))->getJson('/api/admin/activity-logs?from=2026-11-12&to=2026-11-01')->assertUnprocessable();
});

it('is reserved to super admins and admins with the view_logs tier', function () {
    $viewer = User::factory()->admin(Permission::ViewLogs)->create();
    $other = User::factory()->admin(Permission::ViewReports, Permission::BlockUsers)->create();

    $this->bearer(deviceToken($viewer, 'device-viewer-00000001'))->getJson('/api/admin/activity-logs')->assertOk();
    $this->bearer(deviceToken($other, 'device-other-000000001'))->getJson('/api/admin/activity-logs')->assertForbidden();
    $this->bearer(deviceToken($this->emp, 'device-emp-0000000001'))->getJson('/api/admin/activity-logs')->assertForbidden();
});

it('offers no way to modify or delete logs through the API', function () {
    $id = ActivityLog::query()->value('id');
    $token = deviceToken($this->sa);
    $this->bearer($token)->deleteJson("/api/admin/activity-logs/$id")->assertNotFound();
    $this->bearer($token)->putJson("/api/admin/activity-logs/$id", [])->assertNotFound();
    $this->bearer($token)->deleteJson('/api/admin/activity-logs')->assertStatus(405);
});

it('records the IP, device and user agent of the request', function () {
    $this->withHeader('User-Agent', 'TestBrowser/1.0')->bearer(deviceToken($this->emp))->patchJson('/api/me', ['address' => 'Osu'])->assertOk();

    $log = ActivityLog::query()->where('event', 'profile.updated')->latest('id')->first();
    expect($log->ip)->toBe('127.0.0.1')->and($log->user_agent)->toBe('TestBrowser/1.0')->and($log->device_id)->not->toBeNull();
});

it('drops sensitive keys from the context', function () {
    $log = app(ActivityLogger::class)->log('t', 'T', context: ['password' => 'x', 'code' => '123', 'nested' => ['token' => 'y', 'ok' => 1]]);
    expect($log->context)->toBe(['nested' => ['ok' => 1]]);
});

it('records client-side errors on the account', function () {
    $this->bearer(deviceToken($this->emp))->postJson('/api/client-errors', ['message' => 'TypeError: x is undefined', 'app_version' => '2.0.0', 'platform' => 'Android Chrome 120'])
        ->assertStatus(202);

    $log = ActivityLog::query()->where('event', 'client.error')->sole();
    expect($log->type)->toBe(LogType::Error)->and($log->user_id)->toBe($this->emp->id)->and($log->context['platform'])->toBe('Android Chrome 120');
});

it('records server errors without exposing them', function () {
    Route::get('/api/_crash', fn () => throw new RuntimeException('db password is hunter2'))->middleware('auth:sanctum');

    $this->bearer(deviceToken($this->emp))->getJson('/api/_crash')->assertStatus(500)->assertExactJson(['message' => 'Something went wrong. Please try again later.']);
    $log = ActivityLog::query()->where('event', 'server.error')->sole();
    expect($log->user_id)->toBe($this->emp->id)->and(json_encode($log->toArray()))->not->toContain('hunter2');
});

it('purges logs older than the retention period once per day', function () {
    Carbon::setTestNow(Carbon::parse('2025-10-01 10:00', 'Africa/Accra'));
    app(ActivityLogger::class)->log('old.event', 'Old');
    Carbon::setTestNow(Carbon::parse('2026-11-12 10:00', 'Africa/Accra'));

    Artisan::call('attendance:purge-logs');
    expect(ActivityLog::query()->where('event', 'old.event')->exists())->toBeFalse()
        ->and(ActivityLog::query()->where('event', 'profile.updated')->exists())->toBeTrue();

    app(ActivityLogger::class)->log('old.event', 'Not old');
    Artisan::call('attendance:purge-logs');
    expect(ScheduledRun::query()->where('task', 'purge_logs')->count())->toBe(1);
});

it('runs a scheduled task once per run key and retries a failed run', function () {
    $runner = app(ScheduledRunner::class);
    $count = 0;

    expect($runner->once('demo', 'demo:1', null, function () use (&$count) {
        $count++;
        throw new RuntimeException('boom');
    }))->toBeTrue();
    expect(ScheduledRun::query()->where('run_key', 'demo:1')->value('status'))->toBe('failed');

    expect($runner->once('demo', 'demo:1', null, function () use (&$count) {
        $count++;
    }))->toBeTrue();
    expect($runner->once('demo', 'demo:1', null, function () use (&$count) {
        $count++;
    }))->toBeFalse();
    expect($count)->toBe(2)->and(ScheduledRun::query()->where('run_key', 'demo:1')->value('status'))->toBe('succeeded');
});

it('hides super admin events from admins with view_logs unless configured (P1-11)', function () {
    app(ActivityLogger::class)->security('auth.login_succeeded', 'Signed in', LogLevel::Info, $this->sa, $this->sa);
    $admin = User::factory()->admin(Permission::ViewLogs)->create();

    $this->bearer(deviceToken($admin))->getJson('/api/admin/activity-logs')->assertOk()->assertJsonPath('meta.total', 3);
    $this->bearer(deviceToken($this->sa, 'device-test-sa-000001'))->getJson('/api/admin/activity-logs')->assertOk()->assertJsonPath('meta.total', 4);

    app(SettingsService::class)->set(['logs_admins_see_super_admin_events' => true]);
    app()->forgetScopedInstances();
    $this->app['auth']->forgetGuards();
    $this->bearer(deviceToken($admin, 'device-test-adm-00001'))->getJson('/api/admin/activity-logs')->assertOk()->assertJsonPath('meta.total', 4);
});

it('keeps log entries when a device or user they reference is deleted (P1-08)', function () {
    $device = Device::query()->create(['user_id' => $this->emp->id, 'identifier_hash' => str_repeat('a', 64)]);
    $log = ActivityLog::query()->create(['type' => LogType::Security, 'level' => LogLevel::Info, 'event' => 't', 'description' => 'T', 'device_id' => $device->id, 'user_id' => $this->emp->id]);

    $device->delete();
    $this->emp->forceDelete();

    expect($log->fresh()->device_id)->toBe($device->id)->and($log->fresh()->user_id)->toBe($this->emp->id);
});

describe('CSV export (phase 6)', function () {
    it('streams the filtered logs as CSV for super admins and logs the export', function () {
        app(ActivityLogger::class)->log('profile.updated', '=HYPERLINK("http://x")', user: $this->emp);
        $r = $this->bearer(deviceToken($this->sa))->get('/api/admin/activity-logs/export?user_id='.$this->emp->id.'&type=action');
        $r->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        expect($r->headers->get('Content-Disposition'))->toContain('attachment; filename=activity_log_');
        $lines = array_values(array_filter(explode("\n", $r->streamedContent())));
        expect($lines)->toHaveCount(3) // header + 2 action rows of the employee
            ->and($lines[0])->toContain('Date,Type,Level,Event')
            ->and($r->streamedContent())->toContain("'=HYPERLINK")->not->toContain(',=HYPERLINK')->not->toContain('auth.login_failed');
        $log = ActivityLog::query()->where('event', 'logs.exported')->firstOrFail();
        expect($log->type)->toBe(LogType::Security)->and($log->actor_id)->toBe($this->sa->id)
            ->and($log->context['rows'])->toBe(2)->and($log->context['filters']['type'])->toBe('action');
    });

    it('caps the rows at the configured maximum', function () {
        app(SettingsService::class)->set(['log_export_max_rows' => 100]);
        for ($i = 0; $i < 110; $i++) {
            app(ActivityLogger::class)->log('scheduled.y', 'Row '.$i, LogType::Automatic);
        }
        $r = $this->bearer(deviceToken($this->sa))->get('/api/admin/activity-logs/export?event=scheduled.y');
        expect(count(array_filter(explode("\n", $r->streamedContent()))))->toBe(101)
            ->and(ActivityLog::query()->where('event', 'logs.exported')->value('context')['truncated'])->toBeTrue();
    });

    it('is refused to admins, even with view_logs, and validates filters', function () {
        $admin = User::factory()->admin(Permission::ViewLogs)->create();
        $this->bearer(deviceToken($admin))->get('/api/admin/activity-logs/export')->assertForbidden();
        $this->bearer(deviceToken($this->emp))->get('/api/admin/activity-logs/export')->assertForbidden();
        $this->bearer(deviceToken($this->sa))->getJson('/api/admin/activity-logs/export?type=nope')->assertStatus(422);
        expect(ActivityLog::query()->where('event', 'logs.exported')->count())->toBe(0);
    });

    it('is rate limited per super admin', function () {
        app(SettingsService::class)->set(['log_export_per_hour' => 2]);
        $token = deviceToken($this->sa);
        $this->bearer($token)->get('/api/admin/activity-logs/export')->assertOk();
        $this->bearer($token)->get('/api/admin/activity-logs/export')->assertOk();
        $this->bearer($token)->get('/api/admin/activity-logs/export')->assertStatus(429);
    });
});
