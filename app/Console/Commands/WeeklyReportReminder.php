<?php

namespace App\Console\Commands;

use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\WeeklyWindow;
use App\Models\WeeklyReportSubmission;
use App\Notifications\ReportNotice;
use App\Services\ScheduledRunner;
use App\Support\TimeFormat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Report day reminder (default Friday 16:30) to expected employees without a weekly report
 * (in-app + push). Once per report day; caught up until the window closes.
 */
class WeeklyReportReminder extends Command
{
    protected $signature = 'reports:weekly-reminder';

    protected $description = 'Remind expected employees to send their weekly report';

    public function handle(ScheduledRunner $runner, WorkCalendar $calendar, WeeklyWindow $window): int
    {
        $now = $calendar->now();
        $today = $calendar->workDate($now);
        $weekStart = $window->weekStart($today);
        $windowDate = $window->windowDate($weekStart);
        if ($today !== $windowDate || $now->lt($window->reminderAt($windowDate)) || $now->gte($window->closesAt($windowDate))) {
            return self::SUCCESS;
        }
        $sent = 0;
        $runner->once('weekly_reminder', 'weekly_reminder:'.$windowDate, $window->reminderAt($windowDate), function () use ($window, $windowDate, $weekStart, &$sent) {
            $submitted = WeeklyReportSubmission::query()->whereDate('week_start', $weekStart)->pluck('user_id')->all();
            $failed = 0;
            $closes = TimeFormat::time($window->closesAt($windowDate));
            foreach ($window->expectedEmployees($windowDate) as $employee) {
                if (in_array($employee->id, $submitted, true)) {
                    continue;
                }
                $claimed = DB::table('reminder_deliveries')->insertOrIgnore([
                    'kind' => 'weekly_report_reminder', 'user_id' => $employee->id, 'work_date' => $windowDate, 'sent_at' => Carbon::now(),
                ]);
                if ($claimed !== 1) {
                    continue;
                }
                try {
                    $employee->notifyNow(new ReportNotice('weekly_report_reminder', 'Weekly report due',
                        "You have not sent your weekly report yet. Please upload it before {$closes}.", '/reports/weekly', ['week_start' => $weekStart]));
                    $sent++;
                } catch (Throwable $e) {
                    DB::table('reminder_deliveries')->where(['kind' => 'weekly_report_reminder', 'user_id' => $employee->id])->whereDate('work_date', $windowDate)->delete();
                    $failed++;
                    report($e);
                }
            }
            if ($failed > 0) {
                throw new RuntimeException("Weekly reminder failed for {$failed} employee(s)");
            }
        });
        $this->info("Reminded {$sent} employee(s).");

        return self::SUCCESS;
    }
}
