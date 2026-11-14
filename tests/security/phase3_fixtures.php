<?php

// Phase 3 security fixtures. LOCAL ONLY (refuses APP_ENV=production in .env).
// Usage: php tests/security/phase3_fixtures.php > $SCRATCH/p3.json
// Creates admins with given permission sets + a super admin, each with a device-bound token.
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Device;
use App\Models\PermissionRecord;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment('production')) {
    fwrite(STDERR, "refused\n");
    exit(1);
}

$defs = [
    'sa' => [Role::SuperAdmin, []],
    'none' => [Role::Admin, []],
    'onbehalf' => [Role::Admin, ['checkin_for_employee']],
    'late' => [Role::Admin, ['view_lateness', 'approve_mission_checkins']],
    'mission' => [Role::Admin, ['approve_mission_checkins']],
    'leaves' => [Role::Admin, ['manage_leaves']],
    'sched' => [Role::Admin, ['manage_work_schedules']],
];
$out = [];
foreach ($defs as $k => [$role, $perms]) {
    $email = "p3-$k@sectest.invalid";
    $u = User::query()->where('email', $email)->first() ?? new User;
    $u->fill(['first_name' => 'P3', 'last_name' => $k, 'email' => $email, 'role' => $role, 'employee_type' => 'employee', 'status' => UserStatus::Active]);
    $u->password = Hash::make(Str::random(32));
    $u->must_change_password = false;
    $u->save();
    $u->permissionRecords()->sync(PermissionRecord::query()->whereIn('key', $perms)->pluck('id'));
    $u->tokens()->delete();
    $d = Device::query()->firstOrCreate(['user_id' => $u->id, 'identifier_hash' => Device::hashIdentifier("p3-$k")], ['first_login_at' => now(), 'last_seen_at' => now()]);
    $t = $u->createToken('p3');
    $t->accessToken->forceFill(['device_id' => $d->id])->save();
    $out[$k] = ['id' => $u->id, 'token' => $t->plainTextToken];
}
echo json_encode($out);
