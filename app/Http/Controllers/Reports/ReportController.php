<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\ReportBuilder;
use App\Domain\Reports\WeeklyReportService;
use App\Domain\Reports\WeeklyWindow;
use App\Exceptions\ApiError;
use App\Models\DailyReport;
use App\Models\User;
use App\Models\WeeklyReportSubmission;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/** Employee side of the reports: own daily/weekly summaries and weekly PDF upload. */
class ReportController
{
    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly ReportBuilder $builder,
        private readonly WeeklyWindow $window,
        private readonly WeeklyReportService $weekly,
        private readonly SettingsService $settings,
    ) {}

    public function daily(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $user = $this->user($request);
        $date = $data['date'] ?? $this->calendar->workDate();
        $snapshot = DailyReport::query()->where('user_id', $user->id)->whereDate('work_date', $date)->first();

        return response()->json(['data' => $this->builder->daily($user, $date) + ['generated_at' => $snapshot?->generated_at->toIso8601String()]]);
    }

    public function weekly(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $this->builder->weekly($this->user($request), $data['date'] ?? $this->calendar->workDate())]);
    }

    /** Upload status: which week an upload now would belong to, its deadline, and the versions already sent. */
    public function submissionStatus(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $now = $this->calendar->now();
        $target = $this->window->targetWeek($user, $now);
        $weekStart = $target['week_start'] ?? $this->window->weekStart($this->calendar->workDate($now));
        $windowDate = $target['window_date'] ?? $this->window->windowDate($weekStart);
        $deadline = $this->window->deadlineFor($user, $windowDate);
        $versions = WeeklyReportSubmission::query()->where('user_id', $user->id)->whereDate('week_start', $weekStart)->orderByDesc('version')->get();

        return response()->json(['data' => [
            'can_submit' => $target !== null,
            'week_start' => $weekStart,
            'window_date' => $windowDate,
            'opens_at' => $this->window->opensAt($windowDate)->toIso8601String(),
            'closes_at' => $this->window->closesAt($windowDate)->toIso8601String(),
            'deadline' => $deadline->toIso8601String(),
            'is_late_now' => $target !== null && $now->gt($deadline),
            'expected' => $this->window->isExpected($user, $windowDate),
            'max_size_mb' => $this->settings->int('report_pdf_max_mb'),
            'current' => ($c = $versions->firstWhere('is_current', true)) !== null ? $this->weekly->present($c) : null,
            'versions' => $versions->map(fn ($s) => $this->weekly->present($s))->values(),
        ]]);
    }

    public function submit(Request $request): JsonResponse
    {
        $maxKb = $this->settings->int('report_pdf_max_mb') * 1024;
        $request->validate(['file' => ['required', 'file', 'max:'.$maxKb]]);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile || ! $file->isValid() || ! WeeklyReportService::isPdf($file)) {
            throw new ApiError(422, 'Please upload a PDF file.', 'invalid_pdf');
        }
        $submission = $this->weekly->store($this->user($request), $file);

        return response()->json(['data' => $this->weekly->present($submission)], 201);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
