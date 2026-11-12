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

it('allows the retention purge to delete logs only with the purge flag', function () {
    ActivityLog::query()->create(['type' => LogType::Action, 'level' => LogLevel::Info, 'event' => 't', 'description' => 'T']);

    DB::transaction(function () {
        DB::statement("SET LOCAL attendance.allow_log_purge = 'on'");
        DB::table('activity_logs')->delete();
    });

    expect(ActivityLog::query()->count())->toBe(0);
});
