<?php

namespace App\Console\Commands;

use App\Domain\Attendance\WorkCalendar;
use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\User;
use App\Notifications\CheckinReminder as ReminderNotification;
use App\Services\ActivityLogger;
use App\Services\ScheduledRunner;
use App\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Fixed-time check-in reminder (SPECS 6, default 08:05) to employees expected today without a check-in.
 * Runs every minute; sends once per work date (scheduled_runs); a missed run is caught up later the
 * same day, but never after the end of the working day.
 */
class CheckinReminder extends Command
{
    protected $signature = 'attendance:checkin-reminder';

    protected $description = 'Remind expected employees who have not checked in yet';

    public function handle(ScheduledRunner $runner, WorkCalendar $calendar, SettingsService $settings, ActivityLogger $logger): int
    {
        $now = $calendar->now();
        $date = $calendar->workDate($now);
        $reminderAt = $calendar->at($date, $settings->string('checkin_reminder_time', $now));
        $endOfDay = $calendar->at($date, $settings->string('end_of_day_time', $now));
        if ($now->lt($reminderAt) || $now->gte($endOfDay)) {
            $this->info('Not reminder time.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;
        $ran = $runner->once('checkin_reminder', 'checkin_reminder:'.$date, $reminderAt, function () use ($calendar, $settings, $date, $now, &$sent, &$failed) {
            $lateAfter = $settings->string('late_after', $now);
            $checkedIn = Attendance::query()->whereDate('work_date', $date)->pluck('user_id')->all();
            $employees = User::query()->where('role', Role::Employee->value)->where('status', UserStatus::Active->value)
                ->whereNotIn('id', $checkedIn)->get();
            foreach ($employees as $employee) {
                if (! $calendar->dayStatus($employee, $date)->isExpected()) {
                    continue;
                }
                // Claim the recipient first (unique kind/user/date): a retried run skips those already reminded.
                $claimed = DB::table('reminder_deliveries')->insertOrIgnore([
                    'kind' => 'checkin_reminder', 'user_id' => $employee->id, 'work_date' => $date, 'sent_at' => Carbon::now(),
                ]);
                if ($claimed !== 1) {
                    continue;
                }
                try {
                    $employee->notifyNow(new ReminderNotification($date, $lateAfter));
                    // The push copy is queued by PushInAppNotification when the in-app record is stored.
                    $sent++;
                } catch (Throwable $e) {
                    // One failing address must not stop the others; release the claim so the retry resends it.
                    DB::table('reminder_deliveries')->where('kind', 'checkin_reminder')->where('user_id', $employee->id)->whereDate('work_date', $date)->delete();
                    $failed++;
                    report($e);
                }
            }
            if ($failed > 0) {
                // Marks the run failed: the next tick retries only the employees not reminded yet.
                throw new RuntimeException("Check-in reminder failed for {$failed} employee(s)");
            }
        });
        if ($ran) {
            $logger->log('attendance.reminder_sent', "Check-in reminder sent to {$sent} employee(s)", LogType::Automatic, LogLevel::Info, context: ['work_date' => $date, 'count' => $sent]);
        }
        $this->info($ran ? "Reminded {$sent} employee(s)." : 'Already sent today.');

        return self::SUCCESS;
    }
}
