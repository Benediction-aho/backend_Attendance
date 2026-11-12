<?php

namespace App\Console\Commands;

use App\Services\ScheduledRunner;
use App\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deletes activity logs older than the retention period (default 12 months). Once per day, idempotent.
 */
class PurgeActivityLogs extends Command
{
    protected $signature = 'attendance:purge-logs';

    protected $description = 'Delete activity logs older than the configured retention period';

    public function handle(ScheduledRunner $runner, SettingsService $settings): int
    {
        $today = Carbon::now()->startOfDay();
        $deleted = 0;

        $ran = $runner->once('purge_logs', 'purge_logs:'.$today->toDateString(), $today, function () use ($settings, &$deleted) {
            $cutoff = Carbon::now()->subMonthsNoOverflow($settings->int('log_retention_months'));
            // SECURITY DEFINER function owned by the migration role: the runtime role cannot delete logs itself.
            $deleted = (int) DB::scalar('SELECT purge_activity_logs(?)', [$cutoff->toIso8601String()]);
        });

        $this->info($ran ? "Deleted {$deleted} log entries." : 'Already done today.');

        return self::SUCCESS;
    }
}
