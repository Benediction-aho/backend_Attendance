<?php

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\PermissionRecord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('seeds every permission tier', function () {
    expect(PermissionRecord::query()->pluck('key')->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map->value->sort()->values()->all());
});

it('enforces a case-insensitive unique email among non-deleted users', function () {
    User::factory()->create(['email' => 'ama@example.com']);

    expect(fn () => DB::table('users')->insert([
        'first_name' => 'A', 'last_name' => 'B', 'email' => 'AMA@example.com',
        'role' => 'employee', 'employee_type' => 'employee', 'status' => 'active',
    ]))->toThrow(QueryException::class);
});

it('allows reusing the email of a deleted account', function () {
    $old = User::factory()->create(['email' => 'kofi@example.com']);
    $old->delete();

    expect(User::factory()->create(['email' => 'kofi@example.com'])->id)->not->toBe($old->id);
});

it('rejects invalid roles and statuses at database level', function () {
    expect(fn () => DB::table('users')->insert([
        'first_name' => 'A', 'last_name' => 'B', 'email' => 'x@example.com',
        'role' => 'god', 'employee_type' => 'employee', 'status' => 'active',
    ]))->toThrow(QueryException::class);
});

it('keeps activity logs append-only at database level', function () {
    $log = ActivityLog::query()->create([
        'type' => LogType::Action, 'level' => LogLevel::Info, 'event' => 'test', 'description' => 'Test',
    ]);

    expect(fn () => DB::table('activity_logs')->where('id', $log->id)->update(['event' => 'x']))->toThrow(QueryException::class);
    expect(fn () => DB::table('activity_logs')->where('id', $log->id)->delete())->toThrow(QueryException::class);
    expect(fn () => $log->update(['event' => 'x']))->toThrow(LogicException::class);
});

it('purges logs only through the SECURITY DEFINER purge function (P1-07)', function () {
    ActivityLog::query()->create(['type' => LogType::Action, 'level' => LogLevel::Info, 'event' => 't', 'description' => 'T']);

    $old = ActivityLog::query()->create(['type' => LogType::Action, 'level' => LogLevel::Info, 'event' => 'old', 'description' => 'O']);
    DB::statement('ALTER TABLE activity_logs DISABLE TRIGGER USER');
    DB::table('activity_logs')->where('id', $old->id)->update(['created_at' => DB::raw("now() - interval '13 months'")]);
    DB::statement('ALTER TABLE activity_logs ENABLE TRIGGER USER');

    expect((int) DB::scalar('SELECT purge_activity_logs(?)', [now()->addMinute()->toIso8601String()]))->toBe(1)
        ->and(ActivityLog::query()->pluck('event')->all())->toBe(['t']);
});

it('refuses TRUNCATE on the activity log (P1-07)', function () {
    expect(fn () => DB::transaction(fn () => DB::statement('TRUNCATE activity_logs')))->toThrow(QueryException::class);
});

/**
 * True when the unprivileged role used by the two tests below exists and the test user may SET ROLE to it.
 * pg_has_role() raises an error for an unknown role, so the existence is checked first (CI creates the role).
 */
function appTestRoleAvailable(): bool
{
    return (bool) DB::scalar(
        "SELECT CASE WHEN EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'attendance_app_test')
                THEN pg_has_role(current_user, 'attendance_app_test', 'MEMBER') ELSE false END"
    );
}

it('does not let a role that does not own the log delete entries, even with the purge flag (P1-07)', function () {
    if (! appTestRoleAvailable()) {
        $this->markTestSkipped('Local role attendance_app_test not set up (see docs/DEPLOYMENT.md, database roles).');
    }
    $log = ActivityLog::query()->create(['type' => LogType::Action, 'level' => LogLevel::Info, 'event' => 't', 'description' => 'T']);
    DB::statement('GRANT SELECT, DELETE ON activity_logs TO attendance_app_test');
    DB::statement('GRANT EXECUTE ON FUNCTION purge_activity_logs(timestamptz) TO attendance_app_test');

    expect(fn () => DB::transaction(function () {
        DB::statement('SET LOCAL ROLE attendance_app_test');
        DB::statement("SET LOCAL attendance.allow_log_purge = 'on'");
        DB::table('activity_logs')->delete();
    }))->toThrow(QueryException::class);
    DB::statement('RESET ROLE');
    expect(ActivityLog::query()->whereKey($log->id)->exists())->toBeTrue();

    // P1-15: even with EXECUTE granted, the purge never deletes entries younger than the 12-month floor.
    $deleted = DB::transaction(function () {
        DB::statement('SET LOCAL ROLE attendance_app_test');
        $n = (int) DB::scalar("SELECT purge_activity_logs('infinity')");
        DB::statement('RESET ROLE');

        return $n;
    });
    expect($deleted)->toBe(0)->and(ActivityLog::query()->whereKey($log->id)->exists())->toBeTrue();
});

it('does not grant EXECUTE on the purge function to the runtime role (P1-15)', function () {
    if (! appTestRoleAvailable()) {
        $this->markTestSkipped('Local role attendance_app_test not set up.');
    }
    config(['database.app_role' => 'attendance_app_test']);
    DB::statement('GRANT EXECUTE ON FUNCTION purge_activity_logs(timestamptz) TO attendance_app_test');
    (require database_path('migrations/2026_11_14_100000_purge_logs_floor.php'))->up();

    expect(DB::scalar("SELECT has_function_privilege('attendance_app_test', 'purge_activity_logs(timestamptz)', 'EXECUTE')"))->toBeFalse();
});
