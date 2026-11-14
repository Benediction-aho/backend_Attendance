<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Attendance\AttendanceScope;
use App\Domain\Reports\ActivityReportService;
use App\Exceptions\ApiError;
use App\Models\ActivityReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin side of activity reports: the readers of weekly reports (view_reports or receive_weekly_reports).
 * An admin reads employees' reports only; a super admin reads employees' and admins' (AttendanceScope).
 */
class AdminActivityReportController
{
    public function __construct(private readonly ActivityReportService $service, private readonly AttendanceScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $admin = $this->authorize($request);
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = $this->scope->visibleRows(ActivityReport::query(), $admin)->with('user')
            ->where('user_id', '!=', $admin->id)
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', (int) $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '<=', $v))
            ->orderByDesc('submitted_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (ActivityReport $r) => $this->service->present($r, false)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /** One report with a fresh temporary download link. */
    public function show(Request $request, string $report): JsonResponse
    {
        // Authorization before the lookup: no existence oracle for callers without the tier.
        $admin = $this->authorize($request);
        $report = ActivityReport::query()->with('user')->find((int) $report);
        if ($report === null || $report->user === null || ! $admin->can('manageAttendance', $report->user)) {
            throw new ApiError(404, 'Not found.', 'not_found');
        }

        return response()->json(['data' => $this->service->present($report, false)]);
    }

    private function authorize(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        if (! ActivityReportService::canRead($user)) {
            throw new ApiError(403, 'This action is not allowed.');
        }

        return $user;
    }
}
