<?php

namespace App\Console\Commands;

use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\MonthlyReportService;
use App\Domain\Reports\ReportRecipients;
use App\Enums\LogType;
use App\Mail\MonthlyReportMail;
use App\Notifications\ReportNotice;
use App\Services\ActivityLogger;
use App\Services\ScheduledRunner;
use App\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Monthly report (SPECS 7): on monthly_report_day at monthly_report_time, PDF emailed to the super admins
 * and the selected admins, plus an in-app notice. Once per month; caught up for report_catchup_days.
 */
class SendMonthlyReport extends Command
{
    protected $signature = 'reports:monthly';

    protected $description = 'Generate and email the monthly performance report';

    public function handle(ScheduledRunner $runner, WorkCalendar $calendar, SettingsService $settings, MonthlyReportService $monthly, ReportRecipients $recipients, ActivityLogger $logger): int
    {
        $now = $calendar->now();
        $oldest = $now->copy()->subDays($settings->int('report_catchup_days', $now));
        foreach ([$now->copy()->startOfMonth()->subMonthNoOverflow(), $now->copy()->startOfMonth()] as $monthStart) {
            $month = $monthStart->format('Y-m');
            $date = $monthStart->copy()->day($settings->int('monthly_report_day', $monthStart->copy()->setTime(12, 0)))->toDateString();
            $due = $calendar->at($date, $settings->string('monthly_report_time', $calendar->at($date, '12:00')));
            if ($now->lt($due) || $due->lt($oldest)) {
                continue;
            }
            $runner->once('monthly_report', 'monthly_report:'.$month, $due, function () use ($monthly, $recipients, $logger, $month) {
                $report = $monthly->generate($month);
                $label = $report->period_start->format('j M Y').' – '.$report->period_end->format('j M Y');
                $failed = 0;
                $sent = 0;
                foreach ($recipients->monthly() as $recipient) {
                    $claimed = DB::table('report_deliveries')->insertOrIgnore(['kind' => 'monthly_report', 'ref' => $month, 'user_id' => $recipient->id, 'sent_at' => Carbon::now()]);
                    if ($claimed !== 1) {
                        continue;
                    }
                    try {
                        Mail::to($recipient->email)->queue(new MonthlyReportMail($report->path, $report->file_name, $label));
                        $recipient->notifyNow(new ReportNotice('monthly_report_emailed', 'Monthly report sent by email',
                            "The monthly performance report ({$label}) has been sent to your email address.", null, ['month' => $month]));
                        $sent++;
                    } catch (Throwable $e) {
                        DB::table('report_deliveries')->where(['kind' => 'monthly_report', 'ref' => $month, 'user_id' => $recipient->id])->delete();
                        $failed++;
                        report($e);
                    }
                }
                $logger->log('reports.monthly_sent', "Monthly report {$report->file_name} sent to {$sent} recipient(s)", LogType::Automatic, context: ['month' => $month]);
                if ($failed > 0) {
                    throw new RuntimeException("Monthly report delivery failed for {$failed} recipient(s)");
                }
            });
        }

        return self::SUCCESS;
    }
}
