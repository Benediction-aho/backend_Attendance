<?php

namespace App\Http\Controllers\Ops;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs the Laravel scheduler once, for hosts without a permanent scheduler process
 * (e.g. Render's free plan): an external cron service calls this endpoint every minute.
 *
 * Disabled (404) unless SCHEDULER_TICK_TOKEN is set. The caller must send the token in the
 * X-Scheduler-Token header; comparison is constant-time. Every scheduled task is idempotent
 * (scheduled_runs), so duplicate or missed ticks are safe.
 */
class SchedulerTickController extends Controller
{
    public function __invoke(Request $request, ActivityLogger $logger): Response
    {
        $expected = (string) config('services.scheduler.tick_token', '');
        if ($expected === '') {
            abort(404);
        }

        $given = (string) $request->header('X-Scheduler-Token', '');
        if (strlen($given) < 32 || ! hash_equals($expected, $given)) {
            $logger->log('ops.scheduler_tick_refused', 'Scheduler tick refused (missing or wrong token).', LogType::Security, LogLevel::Warning);
            abort(404);
        }

        ignore_user_abort(true);
        set_time_limit(120);
        Artisan::call('schedule:run');

        return response()->noContent();
    }
}
