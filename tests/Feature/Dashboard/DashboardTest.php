<?php

// Phase 6: dashboards computed server-side, sections filtered by permission tier, frozen clock.

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\CheckinAttempt;
use App\Models\Client;
use App\Models\Leave;
use App\Models\Task;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Models\WeeklyReportSubmission;
use App\Models\WorkSchedule;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

function dashClient(User $u, string $date, string $service, int $qty = 1): void
{
    Client::query()->create(['user_id' => $u->id, 'work_date' => $date, 'name' => 'C', 'service' => $service, 'service_key' => mb_strtolower($service), 'quantity' => $qty]);
}

function dashSubmission(User $u, string $weekStart, string $windowDate, bool $late = false): void
{
    WeeklyReportSubmission::query()->create(['user_id' => $u->id, 'week_start' => $weekStart, 'window_date' => $windowDate, 'version' => 1, 'is_current' => true,
        'path' => 'x.pdf', 'original_name' => 'x.pdf', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'submitted_at' => now(), 'is_late' => $late]);
}

beforeEach(function () {
    $c = Carbon::parse('2026-01-01');
    // Wednesday 18 Nov 2026, 10:00 Accra; monthly period 26 Oct → 25 Nov.
    at('2026-11-18 10:00');
    $this->a = User::factory()->create(['first_name' => 'Ama', 'last_name' => 'Mensah', 'created_at' => $c]);
    $this->b = User::factory()->create(['created_at' => $c]);
    $this->leave = User::factory()->create(['created_at' => $c]);
    $this->remote = User::factory()->create(['created_at' => $c]);
    Leave::query()->create(['user_id' => $this->leave->id, 'start_date' => '2026-11-18', 'end_date' => '2026-11-18']);
    WorkSchedule::query()->create(['user_id' => $this->remote->id, 'effective_from' => '2026-01-01', 'days_off' => [], 'remote_days' => [3]]);

    attend($this->a, '2026-11-16');
    attend($this->a, '2026-11-17', late: true);
    attend($this->a, '2026-11-18', late: true);
    attend($this->a, '2026-10-27', late: true);  // in period
    attend($this->a, '2026-10-23', late: true);  // previous period
    Attendance::query()->create(['user_id' => $this->b->id, 'work_date' => '2026-11-18', 'day_status' => 'working', 'check_in_type' => 'mission',
        'check_in_at' => now(), 'is_late' => false, 'late_after' => '08:10', 'mission_status' => 'pending', 'reason' => 'Client visit']);
    CheckinAttempt::query()->create(['user_id' => $this->remote->id, 'work_date' => '2026-11-18', 'outcome' => 'out_of_perimeter', 'distance_m' => 300, 'radius_m' => 50, 'max_accuracy_m' => 50, 'created_at' => now()]);

    Task::query()->create(['user_id' => $this->a->id, 'work_date' => '2026-11-16', 'title' => 'x', 'status' => 'done', 'completed_at' => now()]);
    Task::query()->create(['user_id' => $this->a->id, 'work_date' => '2026-11-18', 'title' => 'y', 'status' => 'done', 'completed_at' => now()]);
    Task::query()->create(['user_id' => $this->a->id, 'work_date' => '2026-11-18', 'title' => 'z', 'status' => 'planned']);
    dashClient($this->a, '2026-11-17', 'Visa', 2);
    dashClient($this->a, '2026-11-18', 'Visa');
    dashClient($this->a, '2026-10-30', 'Permit', 5);
    dashClient($this->a, '2026-10-20', 'Old');       // previous period
    dashClient($this->b, '2026-11-02', 'Permit');

    User::factory()->pending()->create();
    WebauthnCredential::query()->create(['user_id' => $this->b->id, 'credential_id' => 'c1', 'public_key' => 'x', 'user_handle' => 'y', 'status' => 'pending']);
});

describe('employee dashboard', function () {
    it('returns today, week and month figures computed by the server', function () {
        Sanctum::actingAs($this->a);
        $d = $this->getJson('/api/me/dashboard')->assertOk()->json('data');
        expect($d['work_date'])->toBe('2026-11-18')
            ->and($d['today'])->toMatchArray(['day_status' => 'working', 'expected' => true, 'checked_in' => true, 'is_late' => true, 'tasks_planned' => 2, 'tasks_done' => 1, 'clients_count' => 1])
            ->and($d['week'])->toMatchArray(['week_start' => '2026-11-16', 'presence_days' => 3, 'expected_days_so_far' => 3, 'late_count' => 2, 'tasks_done' => 2, 'clients_count' => 2])
            ->and($d['week']['weekly_report']['status'])->toBe('not_open')
            ->and($d['month'])->toMatchArray(['period_start' => '2026-10-26', 'period_end' => '2026-11-25', 'presence_days' => 4, 'late_count' => 3, 'clients_count' => 3]);
    });

    it('follows the weekly report window', function () {
        Sanctum::actingAs($this->a);
        at('2026-11-20 16:30');
        expect($this->getJson('/api/me/dashboard')->json('data.week.weekly_report.status'))->toBe('open');
        attend($this->a, '2026-11-20');
        at('2026-11-20 17:01');
        expect($this->getJson('/api/me/dashboard')->json('data.week.weekly_report.status'))->toBe('missing');
        dashSubmission($this->a, '2026-11-16', '2026-11-20', true);
        expect($this->getJson('/api/me/dashboard')->json('data.week.weekly_report'))->toMatchArray(['status' => 'submitted', 'is_late' => true, 'version' => 1]);
    });

    it('starts a new monthly period on the report day', function () {
        Sanctum::actingAs($this->a);
        at('2026-11-26 09:00');
        expect($this->getJson('/api/me/dashboard')->json('data.month'))->toMatchArray(['period_start' => '2026-11-26', 'period_end' => '2026-12-25', 'late_count' => 0, 'clients_count' => 0]);
    });

    it('is refused to admins and guests', function () {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson('/api/me/dashboard')->assertForbidden();
        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/me/dashboard')->assertUnauthorized();
    });
});

describe('admin dashboard', function () {
    it('gives the super admin every section', function () {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');
        expect($d['today'])->toBe(['expected' => 3, 'checked_in' => 2, 'missing' => 1, 'on_leave' => 1, 'remote' => 1, 'not_working' => 1,
            'late' => 1, 'refused_attempts' => 1, 'missions_pending' => 1])
            ->and($d['week'])->toMatchArray(['week_start' => '2026-11-16', 'window_date' => '2026-11-20', 'weekly_reports_received' => 0, 'weekly_reports_expected' => 4, 'closed' => false])
            ->and($d['month'])->toMatchArray(['period_start' => '2026-10-26', 'period_end' => '2026-11-25', 'late_count' => 3, 'clients_count' => 4])
            ->and($d['month']['services_top'][0])->toMatchArray(['service' => 'Permit', 'quantity' => 6])
            ->and($d['pending'])->toBe(['sign_up_requests' => 1, 'faceid_devices' => 1, 'missions' => 1]);
    });

    it('counts weekly reports received from expected employees only', function () {
        dashSubmission($this->a, '2026-11-16', '2026-11-20');
        Sanctum::actingAs(User::factory()->admin(Permission::ReceiveWeeklyReports)->create());
        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');
        expect($d['week']['weekly_reports_received'])->toBe(1)->and($d)->not->toHaveKeys(['today', 'month', 'pending']);
    });

    it('limits the top services to the configured number', function () {
        dashClient($this->b, '2026-11-03', 'Other');
        app(SettingsService::class)->set(['dashboard_top_services' => 1]);
        Sanctum::actingAs(User::factory()->admin(Permission::ViewStatistics)->create());
        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');
        expect($d['month']['services_top'])->toHaveCount(1)->and($d['month'])->not->toHaveKey('late_count');
    });

    it('filters every section by tier', function (array $perms, array $keys, array $absent) {
        Sanctum::actingAs(User::factory()->admin(...$perms)->create());
        $d = $this->getJson('/api/admin/dashboard')->assertOk()->json('data');
        foreach ($keys as $k) {
            expect(data_get($d, $k))->not->toBeNull("{$k} should be present");
        }
        foreach ($absent as $k) {
            expect(data_get($d, $k))->toBeNull("{$k} should be absent");
        }
    })->with([
        'no tier' => [[], ['work_date'], ['today', 'week', 'month', 'pending']],
        'lateness' => [[Permission::ViewLateness], ['today.expected', 'today.late', 'today.refused_attempts', 'month.late_count'], ['today.missions_pending', 'month.clients_count', 'week', 'pending']],
        'activities' => [[Permission::ViewActivities], ['today.expected', 'today.missing'], ['today.late', 'today.refused_attempts', 'month', 'week', 'pending']],
        'clients' => [[Permission::ViewClients], ['month.clients_count', 'month.services_top'], ['month.late_count', 'today', 'week', 'pending']],
        'reports' => [[Permission::ViewReports], ['week.weekly_reports_expected'], ['today', 'month', 'pending']],
        'missions' => [[Permission::ApproveMissionCheckins], ['today.missions_pending', 'pending.missions'], ['today.late', 'pending.sign_up_requests', 'pending.faceid_devices', 'month', 'week']],
        'approve users' => [[Permission::ApproveUsers], ['pending.sign_up_requests'], ['pending.missions', 'pending.faceid_devices', 'today']],
        'faceid' => [[Permission::ApproveFaceIdDevices], ['pending.faceid_devices'], ['pending.missions', 'pending.sign_up_requests', 'today']],
    ]);

    it('is refused to employees', function () {
        Sanctum::actingAs($this->a);
        $this->getJson('/api/admin/dashboard')->assertForbidden();
    });

    it('does not count accounts that joined after today or blocked accounts', function () {
        User::factory()->create(['created_at' => Carbon::parse('2026-11-19 09:00')]);
        User::factory()->status(UserStatus::Blocked)->create(['created_at' => Carbon::parse('2026-01-01')]);
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        expect($this->getJson('/api/admin/dashboard')->json('data.today.expected'))->toBe(3);
    });
});
