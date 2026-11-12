<?php
// Creates one checked-in employee in the LOCAL security database and prints "TOKEN USER_ID". Never in production.
use App\Models\Attendance; use App\Models\Device; use App\Models\User; use App\Settings\SettingsService;
use App\Domain\Attendance\WorkCalendar; use Illuminate\Support\Carbon;
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (app()->environment('production') || config('database.connections.pgsql.database') === 'attendance_e2e') { fwrite(STDERR, "refused\n"); exit(1); }
$u = User::factory()->create(['created_at' => Carbon::now()->subYear()]);
$cal = app(WorkCalendar::class); $d = $cal->workDate();
Attendance::query()->create(['user_id' => $u->id, 'work_date' => $d, 'day_status' => 'working', 'check_in_type' => 'gps', 'check_in_at' => now(), 'is_late' => false, 'late_after' => '08:10']);
app(SettingsService::class)->set(['tasks_max_per_day' => 5, 'clients_max_per_day' => 5, 'checkin_attempts_per_minute' => 600], null, Carbon::parse('2000-01-01'));
$dev = Device::query()->create(['user_id' => $u->id, 'identifier_hash' => Device::hashIdentifier('dev-sec5'), 'first_login_at' => now(), 'last_seen_at' => now()]);
$t = $u->createToken('device'); $t->accessToken->forceFill(['device_id' => $dev->id])->save();
echo $t->plainTextToken.' '.$u->id."\n";
