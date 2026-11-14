<?php

namespace App\Services;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Models\ScheduledRun;
use App\Settings\SettingsService;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Runs a scheduled task at most once per run key (FIABILITE B6). The unique run_key makes a second
 * attempt (overlapping scheduler, retry after a crash, catch-up tick) a no-op once a run succeeded.
 * A failed run, or a run left "running" longer than scheduled_run_stale_minutes, is retried on the next tick.
 */
class ScheduledRunner
{
    public function __construct(private readonly ActivityLogger $logger, private readonly SettingsService $settings) {}

    /** @return bool true if the task ran now, false if this run key already ran. */
    public function once(string $task, string $runKey, ?Carbon $scheduledFor, Closure $work): bool
    {
        $existing = ScheduledRun::query()->where('run_key', $runKey)->first();
        if ($existing !== null) {
            // A failed run is retried; a run stuck in "running" (process killed, OOM, deploy) is taken over
            // once it is older than the configured staleness. The conditional UPDATE is the atomic claim.
            $staleBefore = Carbon::now()->subMinutes($this->settings->int('scheduled_run_stale_minutes'));
            $claimed = ScheduledRun::query()->whereKey($existing->id)
                ->where(fn ($q) => $q->where('status', 'failed')
                    ->orWhere(fn ($q) => $q->where('status', 'running')->where('started_at', '<', $staleBefore)))
                ->update(['status' => 'running', 'started_at' => Carbon::now(), 'error' => null]);
            if ($claimed !== 1) {
                return false;
            }
            $run = $existing->refresh();
        } else {
            try {
                $run = ScheduledRun::query()->create([
                    'task' => $task, 'run_key' => $runKey, 'status' => 'running',
                    'scheduled_for' => $scheduledFor, 'started_at' => Carbon::now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return false;
            }
        }

        try {
            $work();
            $run->update(['status' => 'succeeded', 'finished_at' => Carbon::now()]);
            $this->logger->log("scheduled.$task", "Scheduled task {$task} completed", LogType::Automatic, LogLevel::Info, context: ['run_key' => $runKey]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => Carbon::now(), 'error' => mb_substr($e::class.': '.$e->getMessage(), 0, 1000)]);
            $this->logger->log("scheduled.$task", "Scheduled task {$task} failed", LogType::Automatic, LogLevel::Error, context: ['run_key' => $runKey]);
            report($e);
        }

        return true;
    }
}
