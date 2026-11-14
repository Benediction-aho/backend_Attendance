<?php

namespace App\Console\Commands;

use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\WeeklyReportService;
use App\Domain\Reports\WeeklyWindow;
use App\Enums\LogType;
use App\Models\User;
use App\Models\WeeklyReportSubmission;
use App\Services\ActivityLogger;
use App\Services\ScheduledRunner;
use App\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Report day close (default Friday 17:00, SPECS 7 "Suivi des envois"): counts who sent the report and
 * notifies + emails the authorized staff with the reports received, even if not everyone sent one.
 * Once per report day; a missed run is caught up for report_catchup_days.
 */
class CloseWeeklyReports extends Command
{
    protected $signature = 'reports:weekly-close';

    protected $description = 'Send the weekly reports received to the authorized staff';

    public function handle(ScheduledRunner $runner, WorkCalendar $calendar, WeeklyWindow $window, WeeklyReportService $service, SettingsService $settings, ActivityLogger $logger): int
    {
        $now = $calendar->now();
        $weekStart = $window->weekStart($calendar->workDate($now));
        $oldest = $now->copy()->subDays($settings->int('report_catchup_days', $now));
        foreach ([Carbon::parse($weekStart)->subWeek()->toDateString(), $weekStart] as $week) {
            $windowDate = $window->windowDate($week);
            $close = $window->closesAt($windowDate);
            if ($now->lt($close) || $close->lt($oldest)) {
                continue;
            }
            $runner->once('weekly_close', 'weekly_close:'.$windowDate, $close, function () use ($window, $service, $logger, $week, $windowDate, $close) {
                $everyone = $window->expectedEmployees($windowDate);
                // UX5-09: nobody was expected that week (catch-up or not): no "0 of 0" email, the run is still done.
                if ($everyone->isEmpty()) {
                    $logger->log('reports.weekly_close_skipped', 'Weekly close skipped: nobody was expected for the week of '
                        .Carbon::parse($week)->format('j M Y').'.', LogType::Automatic, context: ['window_date' => $windowDate]);

                    return;
                }
                // P5-04: the latest version submitted before the close counts (and is attached), even if it was
                // replaced after the close; later versions are forwarded separately.
                $allReceived = WeeklyReportSubmission::query()->with('user')->whereDate('week_start', $week)
                    ->where('submitted_at', '<=', $close)->orderByDesc('version')->get()
                    ->unique('user_id')->sortBy('id')->values();
                // Each group of recipients gets the figures and reports of the people it may see: super admins
                // employees and admins, admins employees only (never another admin's report, nor their own).
                foreach ($service->audiences() as $recipients) {
                    /** @var User $viewer */
                    $viewer = $recipients->first();
                    $expected = $window->expectedEmployees($windowDate, $viewer);
                    if ($expected->isEmpty()) {
                        continue;
                    }
                    $received = $allReceived->filter(fn (WeeklyReportSubmission $s) => $viewer->can('manageAttendance', $s->user))->values();
                    $count = $received->whereIn('user_id', $expected->pluck('id')->all())->count();
                    $title = 'Weekly reports: '.$count.' of '.$expected->count().' received';
                    $body = "{$count} of {$expected->count()} expected employee(s) sent their weekly report for the week of "
                        .Carbon::parse($week)->format('j M Y').'.';
                    // UX5-03: the missing employees are listed one per line in the email (with a disambiguator).
                    $missing = $expected->whereNotIn('id', $received->pluck('user_id')->all())->values();
                    if ($missing->isNotEmpty()) {
                        $body .= ' Not received: '.$missing->count().'.';
                    }
                    $service->deliver('weekly_close', $windowDate, $title, $body, '/admin/reports/weekly?date='.$windowDate, $received, $recipients, $missing);
                }
                // No figures here: the log is also read by admins holding view_logs, who must not see totals that include admins.
                $logger->log('reports.weekly_closed', 'Weekly reports closed for the week of '.Carbon::parse($week)->format('j M Y').'.', LogType::Automatic,
                    context: ['window_date' => $windowDate]);
            });
        }

        return self::SUCCESS;
    }
}
