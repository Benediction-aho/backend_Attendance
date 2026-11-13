<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\ReportBuilder;
use App\Domain\Reports\WeeklyReportService;
use App\Domain\Reports\WeeklyWindow;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
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
 */
class AdminReportController
{
    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly ReportBuilder $builder,
        private readonly WeeklyWindow $window,
        private readonly WeeklyReportService $weekly,
    ) {}

    public function daily(Request $request): JsonResponse
    {
        $admin = $this->authorize($request, [Permission::ViewReports]);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'user_id' => ['nullable', 'integer']]);
        $date = $data['date'] ?? $this->calendar->workDate();
        $users = User::query()->where('role', Role::Employee->value)
            ->when(isset($data['user_id']), fn ($q) => $q->whereKey($data['user_id']), fn ($q) => $q->where('status', UserStatus::Active->value))
            ->orderByRaw('upper(last_name), upper(first_name), id')->get();
        $snapshots = DailyReport::query()->whereDate('work_date', $date)->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        return response()->json(['work_date' => $date, 'data' => $users->map(fn (User $u) => [
            'user' => $this->userSummary($u),
            'day_status' => $this->calendar->dayStatus($u, $date)->value,
            'generated_at' => $snapshots->get($u->id)?->generated_at->toIso8601String(),
        ] + $this->filterDay($admin, $this->builder->daily($u, $date)))->values()]);
    }

    public function userWeekly(Request $request, User $user): JsonResponse
    {
        $admin = $this->authorize($request, [Permission::ViewReports]);
        if ($user->role !== Role::Employee) {
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
        $this->authorize($request, [Permission::ViewReports, Permission::ReceiveWeeklyReports]);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $weekStart = $this->window->weekStart($data['date'] ?? $this->calendar->workDate());
        $windowDate = $this->window->windowDate($weekStart);
        $expected = $this->window->expectedEmployees($windowDate)->pluck('id')->all();
        $current = WeeklyReportSubmission::query()->with('user')->whereDate('week_start', $weekStart)->where('is_current', true)->get()->keyBy('user_id');
        $users = User::query()->where('role', Role::Employee->value)
            ->where(fn ($q) => $q->where('status', UserStatus::Active->value)->orWhereIn('id', $current->keys()))
            ->orderByRaw('upper(last_name), upper(first_name), id')->get();

        return response()->json([
            'week_start' => $weekStart,
            'window_date' => $windowDate,
            'opens_at' => $this->window->opensAt($windowDate)->toIso8601String(),
            'closes_at' => $this->window->closesAt($windowDate)->toIso8601String(),
            'expected_count' => count($expected),
            'received_count' => $current->keys()->intersect($expected)->count(),
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
