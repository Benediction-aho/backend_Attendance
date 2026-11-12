<?php

// Phase 3 fix re-test (P3-02): per-recipient reminder claims, failure mid-run, stale "running"
// takeover for every scheduled task. LOCAL ONLY; runs inside a rolled-back transaction.
// Usage: php tests/security/phase3_fixes.php   (needs attendance:load-fixtures --count=230)
use App\Models\User;
use App\Services\ScheduledRunner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment('production')) {
    fwrite(STDERR, "refused\n");
    exit(1);
}
config(['mail.default' => 'log', 'queue.default' => 'sync']);
$tz = 'Africa/Accra';
$at = fn (string $s) => Carbon::setTestNow(Carbon::parse($s, $tz));
$ok = function (string $name, bool $cond, string $info = '') {
    echo ($cond ? 'PASS ' : 'FAIL ')."$name $info\n";
};
$notif = fn () => DB::table('notifications')->where('type', 'like', '%CheckinReminder%');
$victim = User::query()->where('email', 'load-0215@loadtest.invalid')->firstOrFail();
$fail = true;
Event::listen(NotificationSending::class, function (NotificationSending $e) use (&$fail, $victim) {
    if ($fail && $e->notifiable->id === $victim->id && str_contains($e->notification::class, 'CheckinReminder')) {
        throw new RuntimeException('simulated send failure');
    }
});

DB::beginTransaction();
try {
    $date = '2026-11-25'; // Wednesday
    DB::table('scheduled_runs')->where('run_key', 'like', "%$date%")->delete();
    DB::table('reminder_deliveries')->whereDate('work_date', $date)->delete();
    $n0 = $notif()->count();

    $at("$date 09:00:00");
    Artisan::call('attendance:checkin-reminder');
    $run = DB::table('scheduled_runs')->where('run_key', "checkin_reminder:$date")->first();
    $n1 = $notif()->count();
    $claims1 = DB::table('reminder_deliveries')->whereDate('work_date', $date)->count();
    $ok('failure-mid-run-others-sent', $n1 - $n0 > 1 && $claims1 === $n1 - $n0, 'sent='.($n1 - $n0)." claims=$claims1");
    $ok('failure-mid-run-run-failed', $run?->status === 'failed', (string) $run?->status);
    $ok('failure-claim-released', ! DB::table('reminder_deliveries')->where('user_id', $victim->id)->whereDate('work_date', $date)->exists());
    $ok('failure-no-inapp-for-victim', $notif()->where('notifiable_id', $victim->id)->where('created_at', '>=', Carbon::parse("$date 00:00", $tz))->count() === 0);

    $fail = false;
    $at("$date 09:01:00");
    Artisan::call('attendance:checkin-reminder');
    $n2 = $notif()->count();
    $ok('retry-only-remaining', $n2 - $n1 === 1, 'new='.($n2 - $n1));
    $ok('retry-run-succeeded', DB::table('scheduled_runs')->where('run_key', "checkin_reminder:$date")->value('status') === 'succeeded');
    $dups = DB::table('notifications')->where('type', 'like', '%CheckinReminder%')->where('created_at', '>=', Carbon::parse("$date 00:00", $tz))
        ->select('notifiable_id', DB::raw('count(*) c'))->groupBy('notifiable_id')->having(DB::raw('count(*)'), '>', 1)->count();
    $ok('no-duplicate-recipient', $dups === 0, "dups=$dups");
    Artisan::call('attendance:checkin-reminder');
    $ok('third-tick-noop', $notif()->count() === $n2);

    // Stale "running" run of the reminder: crashed after claiming nobody.
    $d2 = '2026-11-26';
    DB::table('scheduled_runs')->where('run_key', 'like', "%$d2%")->delete();
    DB::table('reminder_deliveries')->whereDate('work_date', $d2)->delete();
    DB::table('scheduled_runs')->insert(['task' => 'checkin_reminder', 'run_key' => "checkin_reminder:$d2", 'status' => 'running',
        'scheduled_for' => Carbon::parse("$d2 08:05", $tz), 'started_at' => Carbon::parse("$d2 08:05", $tz)]);
    $m0 = $notif()->count();
    $at("$d2 08:10:00"); // 5 min old, < 15
    Artisan::call('attendance:checkin-reminder');
    $ok('fresh-running-not-taken-over', $notif()->count() === $m0);
    $at("$d2 08:21:00"); // 16 min old
    Artisan::call('attendance:checkin-reminder');
    $ok('stale-running-reminder-taken-over', $notif()->count() > $m0, 'sent='.($notif()->count() - $m0));

    // Runner-level stale takeover for every task.
    $runner = app(ScheduledRunner::class);
    foreach (['auto_checkout', 'purge_logs', 'checkin_reminder'] as $task) {
        $key = "$task:retest-".uniqid();
        $at('2026-11-27 10:00:00');
        DB::table('scheduled_runs')->insert(['task' => $task, 'run_key' => $key, 'status' => 'running', 'scheduled_for' => now(),
            'started_at' => now()]);
        $hits = 0;
        $at('2026-11-27 10:14:00');
        $runner->once($task, $key, now(), function () use (&$hits) { $hits++; });
        $at('2026-11-27 10:16:00');
        $runner->once($task, $key, now(), function () use (&$hits) { $hits++; });
        $runner->once($task, $key, now(), function () use (&$hits) { $hits++; });
        $ok("stale-takeover-$task", $hits === 1, "hits=$hits");
    }
    // Stale setting honoured
    DB::table('settings')->insert(['key' => 'scheduled_run_stale_minutes', 'value' => json_encode(60), 'effective_from' => '2026-01-01', 'created_at' => now()]);
    app(\App\Settings\SettingsService::class)->flush();
    $runner = app(ScheduledRunner::class);
    $key = 'auto_checkout:retest-setting';
    $at('2026-11-27 10:00:00');
    DB::table('scheduled_runs')->insert(['task' => 'auto_checkout', 'run_key' => $key, 'status' => 'running', 'scheduled_for' => now(), 'started_at' => now()]);
    $hits = 0;
    $at('2026-11-27 10:30:00');
    $runner->once('auto_checkout', $key, now(), function () use (&$hits) { $hits++; });
    $ok('stale-setting-60-respected', $hits === 0, "hits=$hits");
} catch (Throwable $e) {
    echo 'ERROR '.$e::class.': '.$e->getMessage()."\n";
} finally {
    DB::rollBack();
    app(\App\Settings\SettingsService::class)->flush();
}
