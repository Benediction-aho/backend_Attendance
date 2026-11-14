<?php

namespace App\Console\Commands;

use App\Services\AccountService;
use App\Services\AtomicLimiter;
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

    public function handle(ScheduledRunner $runner, SettingsService $settings, AccountService $accounts): int
    {
        $today = Carbon::now()->startOfDay();
        $deleted = 0;

        $ran = $runner->once('purge_logs', 'purge_logs:'.$today->toDateString(), $today, function () use ($settings, &$deleted) {
            $cutoff = Carbon::now()->subMonthsNoOverflow($settings->int('log_retention_months'));
            // SECURITY DEFINER function owned by the migration role, executable only by the owner (P1-15).
            // The function itself never deletes entries younger than 12 months.
            $connection = config('database.log_purge_connection');
            $deleted = (int) DB::connection(is_string($connection) ? $connection : null)->scalar('SELECT purge_activity_logs(?)', [$cutoff->toIso8601String()]);
        });

        AtomicLimiter::prune();
        // REL-03: unanswered sign-up requests do not pile up (idempotent: only rows older than the retention).
        $expired = $accounts->purgeStalePending();
        if ($expired > 0) {
            $this->info("Removed {$expired} unanswered sign-up requests.");
        }
        $this->info($ran ? "Deleted {$deleted} log entries." : 'Already done today.');

        return self::SUCCESS;
    }
}
