<?php

namespace App\Console\Commands;

use App\Domain\Attendance\AttendanceService;
use App\Domain\Attendance\WorkCalendar;
use App\Models\Attendance;
use App\Services\ScheduledRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * "Check-out forgotten" (SPECS 6): closes open attendances at the automatic check-out time (default 00:00).
 * Runs every minute; each work date is recorded once in scheduled_runs; missed runs are caught up;
 * the update only touches rows still open, so a repeated run changes nothing.
 */
class AutoCheckout extends Command
{
    protected $signature = 'attendance:auto-checkout';

    protected $description = 'Automatically check out attendances left open (check-out forgotten)';

    public function handle(ScheduledRunner $runner, AttendanceService $service, WorkCalendar $calendar): int
    {
        $now = Carbon::now();
        $closed = 0;
        $dates = Attendance::query()->whereNull('check_out_at')->whereDate('work_date', '<=', $calendar->workDate($now))
            ->distinct()->orderBy('work_date')->pluck('work_date');

        foreach ($dates as $workDate) {
            $date = Carbon::parse($workDate)->toDateString();
            $cutoff = $calendar->autoCheckoutAt($date);
            if ($cutoff->gt($now)) {
                continue;
            }
            $ran = $runner->once('auto_checkout', 'auto_checkout:'.$date, $cutoff, function () use ($service, $date, $cutoff, &$closed) {
                $closed += $service->closeOpenAttendances($date, $cutoff);
            });
            if (! $ran) {
                // Already recorded for this date: close any straggler (still idempotent).
                $closed += $service->closeOpenAttendances($date, $cutoff);
            }
        }

        $this->info("Closed {$closed} attendance(s).");

        return self::SUCCESS;
    }
}
