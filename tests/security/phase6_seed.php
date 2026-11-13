<?php

// Seeds the LOCAL security database with N employees, one month of attendance/tasks/clients and M activity logs,
// then prints "SUPER_TOKEN EMPLOYEE_TOKEN". Usage: DB_DATABASE=attendance_sec6 php tests/security/phase6_seed.php 300 200000
use App\Models\Device;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.pgsql.database') === 'attendance_e2e' || ! str_starts_with((string) config('database.connections.pgsql.database'), 'attendance_sec')) {
    fwrite(STDERR, "refused\n");
    exit(1);
}
$n = (int) ($argv[1] ?? 300);
$logs = (int) ($argv[2] ?? 0);
$created = Carbon::now()->subYear();
$users = User::factory()->count($n)->create(['created_at' => $created]);
$today = Carbon::now('Africa/Accra')->startOfDay();
$att = $tasks = $clients = [];
foreach ($users as $i => $u) {
    if ($i % 5 === 0) {
        DB::table('work_schedules')->insert(['user_id' => $u->id, 'effective_from' => '2025-01-01', 'days_off' => '[]', 'remote_days' => '[3]', 'created_at' => now(), 'updated_at' => now()]);
    }
    if ($i % 17 === 0) {
        DB::table('leaves')->insert(['user_id' => $u->id, 'start_date' => $today->toDateString(), 'end_date' => $today->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    }
    for ($d = $today->copy()->subDays(31); $d <= $today; $d->addDay()) {
        if ($d->isWeekend()) {
            continue;
        }
        $date = $d->toDateString();
        $at = $d->copy()->setTime(8, rand(0, 30))->utc();
        $att[] = ['user_id' => $u->id, 'work_date' => $date, 'day_status' => 'working', 'check_in_type' => 'gps', 'check_in_at' => $at, 'is_late' => $at->minute > 10, 'late_after' => '08:10', 'created_at' => $at, 'updated_at' => $at];
        for ($k = 0; $k < 3; $k++) {
            $tasks[] = ['user_id' => $u->id, 'work_date' => $date, 'title' => "t{$k}", 'status' => $k ? 'done' : 'planned', 'completed_at' => $k ? $at : null, 'created_at' => $at, 'updated_at' => $at];
            $clients[] = ['user_id' => $u->id, 'work_date' => $date, 'name' => "C{$k}", 'service' => 'Svc'.rand(1, 40), 'service_key' => 'svc'.rand(1, 40), 'quantity' => 1, 'created_at' => $at, 'updated_at' => $at];
        }
    }
    foreach (['attendances' => &$att, 'tasks' => &$tasks, 'clients' => &$clients] as $t => &$rows) {
        foreach (array_chunk($rows, 1000) as $c) {
            DB::table($t)->insert($c);
        }
        $rows = [];
    }
    unset($rows);
}
for ($done = 0; $done < $logs; $done += 5000) {
    DB::statement("insert into activity_logs (type, level, event, description, ip, user_agent, created_at)
        select 'action', 'info', 'load.test', 'row ' || g || ' ' || repeat('x', 200), '10.0.0.1', 'Mozilla/5.0 load', now() - (g || ' seconds')::interval
        from generate_series(?::int, ?::int) g", [$done, min($done + 4999, $logs - 1)]);
}
$sa = User::factory()->superAdmin()->create();
$tok = function (User $u) {
    $dev = Device::query()->create(['user_id' => $u->id, 'identifier_hash' => Device::hashIdentifier('dev-sec6-'.$u->id), 'first_login_at' => now(), 'last_seen_at' => now()]);
    $t = $u->createToken('device');
    $t->accessToken->forceFill(['device_id' => $dev->id])->save();

    return $t->plainTextToken;
};
echo $tok($sa).' '.$tok($users->first())."\n";
