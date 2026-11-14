<?php

// Differential check (phase 6 re-test): WorkCalendar::dayStatuses() (batch) must equal dayStatus() (per user)
// for random schedules (several versions, days off, remote days), leaves (overlapping, multi-day), holidays,
// and WeeklyWindow::expectedEmployees() must equal the per-user filter. LOCAL attendance_sec* database only.
// Usage: DB_DATABASE=attendance_sec6 php tests/security/phase6_calendar_diff.php [users] [days]
use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\WeeklyWindow;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_starts_with((string) config('database.connections.pgsql.database'), 'attendance_sec')) {
    fwrite(STDERR, "refused\n");
    exit(1);
}
mt_srand(6);
$n = (int) ($argv[1] ?? 60);
$days = (int) ($argv[2] ?? 70);
$start = Carbon::parse('2026-10-01');
$cal = app(WorkCalendar::class);
$win = app(WeeklyWindow::class);
DB::beginTransaction();
$users = User::factory()->count($n)->create(['created_at' => '2025-06-01']);
foreach ($users as $i => $u) {
    $versions = mt_rand(0, 3);
    for ($v = 0; $v < $versions; $v++) {
        $from = $start->copy()->addDays(mt_rand(-30, $days))->toDateString();
        DB::table('work_schedules')->insertOrIgnore(['user_id' => $u->id, 'effective_from' => $from,
            'days_off' => json_encode(array_values(array_unique([mt_rand(1, 7), mt_rand(1, 7)]))),
            'remote_days' => json_encode(mt_rand(0, 1) ? [mt_rand(1, 5)] : []), 'created_at' => now(), 'updated_at' => now()]);
    }
    for ($l = mt_rand(0, 3); $l > 0; $l--) {
        $s = $start->copy()->addDays(mt_rand(-5, $days));
        DB::table('leaves')->insert(['user_id' => $u->id, 'start_date' => $s->toDateString(), 'end_date' => $s->copy()->addDays(mt_rand(0, 6))->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }
    if ($i % 13 === 5) {
        $u->forceFill(['status' => UserStatus::Blocked->value])->save();
    }
    if ($i % 11 === 3) {
        $u->forceFill(['created_at' => $start->copy()->addDays(mt_rand(0, $days)), 'approved_at' => null])->save();
    }
}
foreach ([3, 17, 40, 41] as $h) {
    DB::table('holidays')->insertOrIgnore(['date' => $start->copy()->addDays($h)->toDateString(), 'name' => 'H'.$h, 'created_at' => now(), 'updated_at' => now()]);
}
$diff = 0;
$diffExp = 0;
$checked = 0;
for ($d = 0; $d < $days; $d++) {
    $date = $start->copy()->addDays($d)->toDateString();
    $batch = $cal->dayStatuses($users, $date);
    foreach ($users as $u) {
        $checked++;
        if ($batch[$u->id] !== $cal->dayStatus($u, $date)) {
            $diff++;
            echo "DIFF {$date} user {$u->id}: batch={$batch[$u->id]->value} single={$cal->dayStatus($u, $date)->value}\n";
        }
    }
    $exp = $win->expectedEmployees($date)->pluck('id')->sort()->values()->all();
    $ref = User::query()->where('role', Role::Employee->value)->where('status', UserStatus::Active->value)->get()
        ->filter(fn ($u) => $cal->wasMemberOn($u, $date) && $cal->dayStatus($u, $date)->isExpected())->pluck('id')->sort()->values()->all();
    if ($exp !== $ref) {
        $diffExp++;
        echo "DIFF expectedEmployees {$date}: ".count($exp).' vs '.count($ref)."\n";
    }
}
DB::rollBack();
echo "checked {$checked} user-days, status diffs {$diff}, expectedEmployees diffs {$diffExp} over {$days} days\n";
exit($diff + $diffExp > 0 ? 1 : 0);
