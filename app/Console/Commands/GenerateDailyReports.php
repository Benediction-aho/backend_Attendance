<?php

namespace App\Console\Commands;

use App\Domain\Attendance\AttendanceScope;
use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\ReportBuilder;
use App\Enums\UserStatus;
use App\Models\Client;
use App\Models\Task;
use App\Services\ScheduledRunner;
use App\Settings\SettingsService;
use Illuminate\Console\Command;

/**
 * Daily report at daily_report_time (SPECS 7) for each account tracking attendance that day (employees; admins
 * from their start date) and expected, or with tasks/clients.
 * Once per date (scheduled_runs); a missed run is caught up for report_catchup_days.
 */
class GenerateDailyReports extends Command
{
    protected $signature = 'reports:daily';

    protected $description = 'Generate the daily report of each employee';

    public function handle(ScheduledRunner $runner, WorkCalendar $calendar, SettingsService $settings, ReportBuilder $builder, AttendanceScope $scope): int
    {
        $now = $calendar->now();
        $days = $settings->int('report_catchup_days', $now);
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $now->copy()->subDays($i)->toDateString();
            $due = $calendar->at($date, $settings->string('daily_report_time', $calendar->at($date, '12:00')));
            if ($now->lt($due)) {
                continue;
            }
            $count = 0;
            $ran = $runner->once('daily_report', 'daily_report:'.$date, $due, function () use ($calendar, $builder, $scope, $date, &$count) {
                $withData = Task::query()->whereDate('work_date', $date)->pluck('user_id')
                    ->merge(Client::query()->whereDate('work_date', $date)->pluck('user_id'))->unique()->all();
                $users = $scope->population(null, $date, $withData)->get();
                foreach ($users as $user) {
                    if (in_array($user->id, $withData, true) || ($user->status === UserStatus::Active && $scope->tracksOn($user, $date) && $calendar->wasMemberOn($user, $date) && $calendar->dayStatus($user, $date)->isExpected())) {
                        $builder->snapshot($user, $date);
                        $count++;
                    }
                }
            });
            if ($ran) {
                $this->info("Daily reports for {$date}: {$count}.");
            }
        }

        return self::SUCCESS;
    }
}
