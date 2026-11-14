<?php

// Phase 3 frozen-clock checks (lateness, work date, intent across midnight, early check-out,
// auto check-out and reminder idempotency / catch-up). LOCAL ONLY. Everything runs inside a DB
// transaction that is rolled back at the end. Mail goes to the log mailer.
// Usage: php tests/security/phase3_clock.php
use App\Domain\Attendance\AttendanceService;
use App\Exceptions\ApiError;
use App\Models\Attendance;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
$svc = fn () => app(AttendanceService::class);
$users = User::query()->where('email', 'like', 'load-02%@loadtest.invalid')->orderBy('id')->take(12)->get()->values();
$ok = function (string $name, bool $cond, string $info = '') {
    echo ($cond ? 'PASS ' : 'FAIL ')."$name $info\n";
};
$code = function (callable $f) {
    try {
        $f();

        return 'ok';
    } catch (ApiError $e) {
        return $e->getMessage();
    }
};
$gps = ['lat' => 5.595223, 'lng' => -0.216703, 'accuracy' => 5.0];

DB::beginTransaction();
try {
    DB::table('scheduled_runs')->where('run_key', 'like', '%2026-11-16%')->delete();
    // Lateness boundary (Monday 2026-11-16, late_after 08:10).
    $at('2026-11-16 08:09:59.999');
    $i = $svc()->createIntent($users[0], 'gps', $gps, null, null, null);
    [$a] = $svc()->confirm($users[0], $i->id, null);
    $ok('late-08:09:59.999', $a->is_late === false);
    $at('2026-11-16 08:10:00');
    $i = $svc()->createIntent($users[1], 'gps', $gps, null, null, null);
    [$a] = $svc()->confirm($users[1], $i->id, null);
    $ok('late-08:10:00', $a->is_late === true);
    // Intent at 08:09:50, confirm at 08:10:05 -> confirmation time (Q16).
    $at('2026-11-16 08:09:50');
    $i = $svc()->createIntent($users[2], 'gps', $gps, null, null, null);
    $at('2026-11-16 08:10:05');
    [$a] = $svc()->confirm($users[2], $i->id, null);
    $ok('late-uses-confirm-time', $a->is_late === true);

    // Midnight boundary: intent 23:59:30 Monday, confirm 00:00:10 Tuesday -> refused.
    $at('2026-11-16 23:59:30');
    $i = $svc()->createIntent($users[3], 'gps', $gps, null, null, null);
    $at('2026-11-17 00:00:10');
    $ok('intent-across-midnight', $code(fn () => $svc()->confirm($users[3], $i->id, null)) !== 'ok');
    $at('2026-11-16 23:59:59');
    $i = $svc()->createIntent($users[4], 'gps', $gps, null, null, null);
    [$a] = $svc()->confirm($users[4], $i->id, null);
    $ok('workdate-23:59:59', $a->work_date->toDateString() === '2026-11-16');

    // Early check-out boundary (end_of_day 17:00).
    $at('2026-11-16 16:59:59');
    $ok('checkout-16:59:59-needs-reason', $code(fn () => $svc()->checkOut($users[0], null, null)) !== 'ok');
    $at('2026-11-16 17:00:00');
    $a = $svc()->checkOut($users[0], null, null);
    $ok('checkout-17:00-no-reason', $a->early_leave === false);

    // Auto check-out: open rows for users[1], [2], [4] on 2026-11-16.
    $at('2026-11-16 23:59:00');
    Artisan::call('attendance:auto-checkout');
    $ok('auto-before-midnight-noop', Attendance::query()->whereDate('work_date', '2026-11-16')->whereNull('check_out_at')->count() === 3);
    $at('2026-11-17 09:30:00'); // missed midnight run, caught up later
    Artisan::call('attendance:auto-checkout');
    $rows = Attendance::query()->whereDate('work_date', '2026-11-16')->whereIn('user_id', [$users[1]->id, $users[2]->id, $users[4]->id])->get();
    $ok('auto-catch-up', $rows->count() === 3 && $rows->every(fn ($r) => $r->check_out_type === Attendance::CHECKOUT_AUTO && $r->check_out_at->eq(Carbon::parse('2026-11-17 00:00', $tz))), json_encode($rows->map(fn ($r) => [$r->check_out_type, (string) $r->check_out_at])));
    $u1 = $rows->first()->updated_at;
    Artisan::call('attendance:auto-checkout');
    $ok('auto-run-twice', Attendance::query()->whereDate('work_date', '2026-11-16')->where('check_out_type', Attendance::CHECKOUT_AUTO)->count() === 3
        && DB::table('scheduled_runs')->where('run_key', 'auto_checkout:2026-11-16')->count() === 1);
    $ok('manual-checkout-not-overwritten', Attendance::query()->where('user_id', $users[0]->id)->whereDate('work_date', '2026-11-16')->value('check_out_type') === 'manual');
    $ok('checkout-after-auto-refused', $code(fn () => $svc()->checkOut($users[1], null, 'x')) !== 'ok');

    // Reminder (Monday 2026-11-16 should already have run key; use Wednesday 2026-11-18).
    DB::table('scheduled_runs')->where('run_key', 'checkin_reminder:2026-11-18')->delete();
    $n0 = DB::table('notifications')->where('type', 'like', '%CheckinReminder%')->count();
    $at('2026-11-18 08:04:00');
    Artisan::call('attendance:checkin-reminder');
    $ok('reminder-before-time-noop', DB::table('notifications')->where('type', 'like', '%CheckinReminder%')->count() === $n0);
    // users[5] checked in, users[6] day off on Wednesday (admin schedule), users[7] on leave
    $at('2026-11-18 07:30:00');
    $i = $svc()->createIntent($users[5], 'gps', $gps, null, null, null);
    $svc()->confirm($users[5], $i->id, null);
    DB::table('work_schedules')->where('user_id', $users[6]->id)->delete();
    DB::table('work_schedules')->insert(['user_id' => $users[6]->id, 'effective_from' => '2026-11-01', 'days_off' => '[3]', 'remote_days' => '[]', 'set_by' => $users[6]->id, 'set_by_employee' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('leaves')->insert(['user_id' => $users[7]->id, 'start_date' => '2026-11-18', 'end_date' => '2026-11-18', 'created_by' => $users[7]->id, 'created_at' => now(), 'updated_at' => now()]);
    $at('2026-11-18 11:00:00'); // missed 08:05, catch up
    Artisan::call('attendance:checkin-reminder');
    $n1 = DB::table('notifications')->where('type', 'like', '%CheckinReminder%')->count();
    Artisan::call('attendance:checkin-reminder');
    $at('2026-11-18 11:00:30');
    Artisan::call('attendance:checkin-reminder');
    $n2 = DB::table('notifications')->where('type', 'like', '%CheckinReminder%')->count();
    $who = DB::table('notifications')->where('type', 'like', '%CheckinReminder%')->pluck('notifiable_id')->all();
    $ok('reminder-catch-up-sent', $n1 > $n0, 'sent='.($n1 - $n0));
    $ok('reminder-run-twice-no-dup', $n2 === $n1);
    $ok('reminder-not-to-checked-in', ! in_array($users[5]->id, $who));
    $ok('reminder-not-to-day-off', ! in_array($users[6]->id, $who));
    $ok('reminder-not-to-leave', ! in_array($users[7]->id, $who));
    $ok('reminder-to-expected', in_array($users[8]->id, $who));
    DB::table('scheduled_runs')->where('run_key', 'checkin_reminder:2026-11-19')->delete();
    $at('2026-11-19 17:00:00');
    $before = DB::table('notifications')->where('type', 'like', '%CheckinReminder%')->count();
    Artisan::call('attendance:checkin-reminder');
    $ok('reminder-not-after-end-of-day', DB::table('notifications')->where('type', 'like', '%CheckinReminder%')->count() === $before);
} finally {
    DB::rollBack();
    Carbon::setTestNow();
}
