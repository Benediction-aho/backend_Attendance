<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Attendance\AttendanceScope;
use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\ReportBuilder;
use App\Domain\Reports\WeeklyReportService;
use App\Domain\Reports\WeeklyWindow;
use App\Enums\Permission;
use App\Exceptions\ApiError;
use App\Http\Presenters\EmployeeSummary;
use App\Models\DailyReport;
use App\Models\User;
use App\Models\WeeklyReportSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin views of reports. Daily/weekly summaries: view_reports (task titles only with view_tasks,
 * client names only with view_clients). Weekly PDFs: view_reports or receive_weekly_reports.
 * Whose reports: an admin sees employees only; a super admin sees employees and admins (AttendanceScope).
 */
class AdminReportController
{
    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly ReportBuilder $builder,
        private readonly WeeklyWindow $window,
        private readonly WeeklyReportService $weekly,
        private readonly AttendanceScope $scope,
    ) {}

    public function daily(Request $request): JsonResponse
    {
        $admin = $this->authorize($request, [Permission::ViewReports]);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'user_id' => ['nullable', 'integer']]);
        $date = $data['date'] ?? $this->calendar->workDate();
        $users = (isset($data['user_id'])
            ? User::query()->whereKey($data['user_id'])->whereKeyNot($admin->id)->whereIn('role', AttendanceScope::rolesVisibleTo($admin))
            : $this->scope->population($admin, $date))
            ->orderByRaw('upper(last_name), upper(first_name), id')->get();
        $snapshots = DailyReport::query()->where('work_date', $date)->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');
        // UX6-08: an employee who had not joined yet on that date is not part of the day (unless data exists for it).
        if (! isset($data['user_id'])) {
            $users = $users->filter(fn (User $u) => $snapshots->has($u->id) || $this->calendar->wasMemberOn($u, $date))->values();
        }
        $statuses = $this->calendar->dayStatuses($users, $date);

        // UXC-05: neutral totals (employees + admins for a super admin, employees only for an admin).
        $roles = $users->countBy(fn (User $u) => $u->role->value);
        $totals = ['people' => $users->count(), 'employees' => (int) ($roles['employee'] ?? 0), 'admins' => (int) ($roles['admin'] ?? 0),
            'with_report' => $snapshots->count()];

        return response()->json(['work_date' => $date, 'totals' => $totals, 'data' => $users->map(fn (User $u) => [
            'user' => $this->userSummary($u),
            'day_status' => $statuses[$u->id]->value,
            'generated_at' => $snapshots->get($u->id)?->generated_at->toIso8601String(),
        ] + $this->filterDay($admin, $this->builder->daily($u, $date)))->values()]);
    }

    public function userWeekly(Request $request, User $user): JsonResponse
    {
        $admin = $this->authorize($request, [Permission::ViewReports]);
        if (! $admin->can('manageAttendance', $user)) {
            throw new ApiError(404, 'Not found.', 'not_found');
        }
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $week = $this->builder->weekly($user, $data['date'] ?? $this->calendar->workDate());
        if (! self::canSeeWeeklyClients($admin)) {
            unset($week['clients']);
        }

        return response()->json(['user' => $this->userSummary($user), 'data' => $week]);
    }

    /** Weekly window of the week containing `date`: expected employees, who sent, current PDFs. */
    public function weekly(Request $request): JsonResponse
    {
        $admin = $this->authorize($request, [Permission::ViewReports, Permission::ReceiveWeeklyReports]);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $weekStart = $this->window->weekStart($data['date'] ?? $this->calendar->workDate());
        $windowDate = $this->window->windowDate($weekStart);
        $expected = $this->window->expectedEmployees($windowDate, $admin)->pluck('id')->all();
        $current = $this->scope->visibleRows(WeeklyReportSubmission::query(), $admin)->with('user')->whereDate('week_start', $weekStart)->where('is_current', true)->get()->keyBy('user_id');
        $users = $this->scope->population($admin, $windowDate, $current->keys())
            ->orderByRaw('upper(last_name), upper(first_name), id')->get();

        return response()->json([
            'week_start' => $weekStart,
            'window_date' => $windowDate,
            'opens_at' => $this->window->opensAt($windowDate)->toIso8601String(),
            'closes_at' => $this->window->closesAt($windowDate)->toIso8601String(),
            'expected_count' => count($expected),
            'received_count' => $current->keys()->intersect($expected)->count(),
            // UX5-03: expected employees without a current report, same EmployeeSummary as the rows.
            'missing' => $users->filter(fn (User $u) => in_array($u->id, $expected, true) && ! $current->has($u->id))
                ->map(fn (User $u) => $this->userSummary($u))->values(),
            'data' => $users->map(fn (User $u) => [
                'user' => $this->userSummary($u),
                'expected' => in_array($u->id, $expected, true),
                'submission' => ($s = $current->get($u->id)) !== null ? $this->weekly->present($s) : null,
            ])->values(),
        ]);
    }

    public function submission(Request $request, string $submission): JsonResponse
    {
        // Authorization before the lookup: no existence oracle for callers without the tier.
        $admin = $this->authorize($request, [Permission::ViewReports, Permission::ReceiveWeeklyReports]);
        $submission = WeeklyReportSubmission::query()->with('user')->find((int) $submission)
            ?? throw new ApiError(404, 'Not found.', 'not_found');
        // An admin's report is visible to super admins only; same answer as an unknown id.
        if (! $admin->can('manageAttendance', $submission->user)) {
            throw new ApiError(404, 'Not found.', 'not_found');
        }
        $versions = WeeklyReportSubmission::query()->with('user')->where('user_id', $submission->user_id)
            ->whereDate('week_start', $submission->week_start->toDateString())->orderByDesc('version')->get();
        $week = $this->builder->weekly($submission->user, $submission->week_start->toDateString());

        return response()->json(['data' => $this->weekly->present($submission, true) + [
            'versions' => $versions->map(fn ($v) => $this->weekly->present($v))->values(),
            // SPECS 7: the report comes with the employee's clients of the week (names: P5-02 rule below).
            'week' => ['clients_count' => $week['clients_count'], 'services' => $week['services']]
                + (self::canSeeWeeklyClients($admin) ? ['clients' => $week['clients']] : []),
        ]]);
    }

    /**
     * P5-02 rule (one place): client names of a week are shown to super admins, view_clients holders and
     * receive_weekly_reports holders (SPECS 7: the weekly report sent to the recipients is accompanied by
     * each employee's client list; the same list is in their weekly emails). view_reports alone: counts and
     * services only. Daily reports keep requiring view_clients.
     */
    public static function canSeeWeeklyClients(User $admin): bool
    {
        return $admin->hasPermission(Permission::ViewClients) || $admin->hasPermission(Permission::ReceiveWeeklyReports);
    }

    /** @param list<Permission> $anyOf */
    private function authorize(Request $request, array $anyOf): User
    {
        /** @var User $user */
        $user = $request->user();
        foreach ($anyOf as $permission) {
            if ($user->hasPermission($permission)) {
                return $user;
            }
        }
        throw new ApiError(403, 'This action is not allowed.');
    }

    /** @return array<string, mixed> */
    private function userSummary(User $u): array
    {
        return EmployeeSummary::of($u);
    }

    /**
     * @param  array<string, mixed>  $day
     * @return array<string, mixed>
     */
    private function filterDay(User $admin, array $day): array
    {
        unset($day['work_date']);
        if (! $admin->hasPermission(Permission::ViewTasks)) {
            unset($day['tasks']);
        }
        if (! $admin->hasPermission(Permission::ViewClients)) {
            unset($day['clients']);
        }

        return $day;
    }
}
