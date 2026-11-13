<?php

namespace App\Domain\Dashboard;

use App\Domain\Attendance\DayStatus;
use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\MonthlyReportService;
use App\Domain\Reports\ReportBuilder;
use App\Domain\Reports\WeeklyWindow;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\CheckinAttempt;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Models\WeeklyReportSubmission;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Dashboard summaries (phase 6, SPECS 2 and 7). Every figure is computed here, on the server, from the
 * server clock (Africa/Accra); the client never counts. Admin sections are included only when the
 * viewer holds the tier that allows them (a missing section = not allowed, never a zero).
 */
class DashboardService
{
    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly WeeklyWindow $window,
        private readonly MonthlyReportService $monthly,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Monthly period (default 26 → 25) containing $date: the one whose report is sent on the next report day.
     *
     * @return array{start: string, end: string}
     */
    public function periodContaining(string $date): array
    {
        $d = Carbon::createFromFormat('Y-m-d', $date, $this->calendar->timezone());
        if ($d === null) {
            throw new \InvalidArgumentException('Invalid date');
        }
        $day = $this->settings->int('monthly_report_day', $d->copy()->setTime(12, 0));
        $month = $d->day >= $day ? $d->copy()->startOfMonth()->addMonthNoOverflow() : $d->copy()->startOfMonth();

        return $this->monthly->period($month->format('Y-m'));
    }

    /** @return array<string, mixed> */
    public function employee(User $user): array
    {
        $now = $this->calendar->now();
        $today = $this->calendar->workDate($now);
        $status = $this->calendar->dayStatus($user, $today);
        $attendance = Attendance::query()->where('user_id', $user->id)->where('work_date', $today)->first();
        $todayTasks = Task::query()->where('user_id', $user->id)->where('work_date', $today)->get();

        $weekStart = $this->window->weekStart($today);
        $windowDate = $this->window->windowDate($weekStart);
        $weekEnd = $this->window->weekEnd($weekStart);
        $weekAttendances = Attendance::query()->where('user_id', $user->id)->where('work_date', '>=', $weekStart)->where('work_date', '<=', $today)->get();
        $expectedDays = 0;
        for ($d = Carbon::parse($weekStart); $d->toDateString() <= $today; $d->addDay()) {
            $date = $d->toDateString();
            if ($this->calendar->wasMemberOn($user, $date) && $this->calendar->dayStatus($user, $date)->isExpected()) {
                $expectedDays++;
            }
        }
        $weekTasks = Task::query()->where('user_id', $user->id)->where('work_date', '>=', $weekStart)->where('work_date', '<=', $weekEnd)->get();
        $weekClients = Client::query()->where('user_id', $user->id)->where('work_date', '>=', $weekStart)->where('work_date', '<=', $weekEnd)->count();

        $period = $this->periodContaining($today);

        return [
            'server_time' => $now->toIso8601String(),
            'work_date' => $today,
            'today' => [
                'day_status' => $status->value,
                'expected' => $status->isExpected(),
                'checked_in' => $attendance !== null,
                'checked_out' => $attendance?->check_out_at !== null,
                'check_in_at' => $attendance?->check_in_at->toIso8601String(),
                'check_in_type' => $attendance?->check_in_type,
                'is_late' => $attendance?->is_late,
                'mission_status' => $attendance?->mission_status,
                'tasks_planned' => $todayTasks->count(),
                'tasks_done' => $todayTasks->where('status', 'done')->count(),
                'clients_count' => Client::query()->where('user_id', $user->id)->where('work_date', $today)->count(),
            ],
            'week' => [
                'week_start' => $weekStart,
                'week_end' => $weekEnd,
                'presence_days' => $weekAttendances->count(),
                'expected_days_so_far' => $expectedDays,
                'late_count' => $weekAttendances->where('is_late', true)->count(),
                'tasks_planned' => $weekTasks->count(),
                'tasks_done' => $weekTasks->where('status', 'done')->count(),
                'clients_count' => $weekClients,
                'weekly_report' => $this->weeklyReportStatus($user, $weekStart, $windowDate, $now),
            ],
            'month' => [
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'presence_days' => Attendance::query()->where('user_id', $user->id)->where('work_date', '>=', $period['start'])->where('work_date', '<=', $today)->count(),
                'late_count' => Attendance::query()->where('user_id', $user->id)->where('work_date', '>=', $period['start'])->where('work_date', '<=', $today)->where('is_late', true)->count(),
                'clients_count' => Client::query()->where('user_id', $user->id)->where('work_date', '>=', $period['start'])->where('work_date', '<=', $today)->count(),
            ],
        ];
    }

    /**
     * submitted | not_open (before the window) | open (window open, deadline not passed) | missing | not_expected.
     *
     * @return array<string, mixed>
     */
    private function weeklyReportStatus(User $user, string $weekStart, string $windowDate, Carbon $now): array
    {
        $current = WeeklyReportSubmission::query()->where('user_id', $user->id)->where('week_start', $weekStart)->where('is_current', true)->first();
        $expected = $this->window->isExpected($user, $windowDate);
        $deadline = $this->window->deadlineFor($user, $windowDate);
        $opens = $this->window->opensAt($windowDate);
        $status = match (true) {
            $current !== null => 'submitted',
            $now->lt($opens) => 'not_open',
            $now->lte($deadline) => 'open',
            $expected => 'missing',
            default => 'not_expected',
        };

        return [
            'status' => $status,
            'expected' => $expected,
            'window_date' => $windowDate,
            'opens_at' => $opens->toIso8601String(),
            'deadline' => $deadline->toIso8601String(),
            'submitted_at' => $current?->submitted_at->toIso8601String(),
            'is_late' => $current?->is_late,
            'version' => $current?->version,
        ];
    }

    /** @return array<string, mixed> */
    public function admin(User $viewer): array
    {
        $now = $this->calendar->now();
        $today = $this->calendar->workDate($now);
        $can = fn (Permission ...$ps) => collect($ps)->contains(fn (Permission $p) => $viewer->hasPermission($p));
        $out = ['server_time' => $now->toIso8601String(), 'work_date' => $today];

        if (Gate::forUser($viewer)->allows('view-attendance')) {
            $out['today'] = $this->adminToday($today, $can(Permission::ViewLateness), $can(Permission::ApproveMissionCheckins));
        }
        if ($can(Permission::ViewReports, Permission::ReceiveWeeklyReports)) {
            $weekStart = $this->window->weekStart($today);
            $windowDate = $this->window->windowDate($weekStart);
            $expected = $this->window->expectedEmployees($windowDate)->pluck('id')->all();
            $received = WeeklyReportSubmission::query()->where('week_start', $weekStart)->where('is_current', true)->pluck('user_id')->intersect($expected)->count();
            $out['week'] = [
                'week_start' => $weekStart,
                'week_end' => $this->window->weekEnd($weekStart),
                'window_date' => $windowDate,
                'closes_at' => $this->window->closesAt($windowDate)->toIso8601String(),
                'closed' => $now->gte($this->window->closesAt($windowDate)),
                'weekly_reports_received' => $received,
                'weekly_reports_expected' => count($expected),
            ];
        }
        $seeLate = $can(Permission::ViewLateness);
        $seeClients = $can(Permission::ViewClients, Permission::ViewStatistics);
        if ($seeLate || $seeClients) {
            $period = $this->periodContaining($today);
            $month = ['period_start' => $period['start'], 'period_end' => $period['end'], 'to_date' => $today];
            $employees = User::withTrashed()->select('id')->where('role', Role::Employee->value);
            if ($seeLate) {
                $month['late_count'] = Attendance::query()->whereIn('user_id', $employees)->where('work_date', '>=', $period['start'])
                    ->where('work_date', '<=', $today)->where('is_late', true)->count();
            }
            if ($seeClients) {
                $base = Client::query()->whereIn('user_id', $employees)->where('work_date', '>=', $period['start'])->where('work_date', '<=', $today);
                $month['clients_count'] = (clone $base)->count();
                // P6-05: aggregate in SQL instead of loading every client row (same shape/order as ReportBuilder::services:
                // quantity desc, key asc; label = most used spelling, first seen on a tie).
                $top = (clone $base)->toBase()->selectRaw('service_key, sum(quantity) as qty')->groupBy('service_key')
                    ->orderByDesc('qty')->orderBy('service_key')->limit($this->settings->int('dashboard_top_services', $now))->get();
                $labels = (clone $base)->toBase()->whereIn('service_key', $top->pluck('service_key'))
                    ->selectRaw('service_key, service, count(*) as n, min(id) as first_id')->groupBy('service_key', 'service')
                    ->orderByDesc('n')->orderBy('first_id')->get()->groupBy('service_key');
                $month['services_top'] = $top->map(fn ($r) => [
                    'service' => (string) $labels->get($r->service_key)?->first()?->service,
                    'service_key' => (string) $r->service_key,
                    'quantity' => (int) $r->qty,
                ])->values()->all();
            }
            $out['month'] = $month;
        }
        $pending = [];
        if ($can(Permission::ApproveUsers)) {
            $pending['sign_up_requests'] = User::query()->where('status', UserStatus::Pending->value)->count();
        }
        if ($can(Permission::ApproveFaceIdDevices)) {
            $pending['faceid_devices'] = WebauthnCredential::query()->where('status', WebauthnCredential::PENDING)
                ->when(! $viewer->isSuperAdmin(), fn ($q) => $q->whereIn('user_id', User::query()->select('id')->where('role', Role::Employee->value)))
                ->count();
        }
        if ($can(Permission::ApproveMissionCheckins)) {
            $pending['missions'] = Attendance::query()->where('check_in_type', Attendance::TYPE_MISSION)->where('mission_status', 'pending')->count();
        }
        if ($pending !== []) {
            $out['pending'] = $pending;
        }

        return $out;
    }

    /** @return array<string, int> */
    private function adminToday(string $today, bool $seeLate, bool $seeMissions): array
    {
        $attendances = Attendance::query()->where('work_date', $today)
            ->whereIn('user_id', User::withTrashed()->select('id')->where('role', Role::Employee->value))->get()->keyBy('user_id');
        $employees = User::query()->where('role', Role::Employee->value)->where('status', UserStatus::Active->value)->get()
            ->filter(fn (User $u) => $this->calendar->wasMemberOn($u, $today));
        $statuses = $this->calendar->dayStatuses($employees, $today);
        // P6-04: checked_in counts only the employees counted in "expected"; other check-ins (remote/day-off
        // workers, people checking in while not expected) are reported separately. Blocked/deleted accounts never count.
        $counts = ['expected' => 0, 'checked_in' => 0, 'checked_in_not_expected' => 0, 'missing' => 0, 'on_leave' => 0, 'remote' => 0, 'not_working' => 0, 'day_off' => 0, 'holiday' => 0];
        foreach ($employees as $u) {
            $status = $statuses[$u->id];
            if ($status->isExpected()) {
                $counts['expected']++;
                $attendances->has($u->id) ? $counts['checked_in']++ : $counts['missing']++;
            } else {
                $counts['not_working']++;
                if ($attendances->has($u->id)) {
                    $counts['checked_in_not_expected']++;
                }
            }
            if ($status === DayStatus::Leave) {
                $counts['on_leave']++;
            }
            if ($status === DayStatus::Remote) {
                $counts['remote']++;
            }
            // UX6-03: not_working = on_leave + day_off + holiday (day_off includes weekly non-working days).
            if ($status === DayStatus::DayOff || $status === DayStatus::NonWorking) {
                $counts['day_off']++;
            }
            if ($status === DayStatus::Holiday) {
                $counts['holiday']++;
            }
        }
        if ($seeLate) {
            $counts['late'] = $attendances->where('is_late', true)->count();
            $counts['refused_attempts'] = CheckinAttempt::query()->where('work_date', $today)
                ->whereIn('user_id', User::withTrashed()->select('id')->where('role', Role::Employee->value))->count();
        }
        if ($seeMissions) {
            $counts['missions_pending'] = $attendances->where('check_in_type', Attendance::TYPE_MISSION)->where('mission_status', 'pending')->count();
        }

        return $counts;
    }
}
