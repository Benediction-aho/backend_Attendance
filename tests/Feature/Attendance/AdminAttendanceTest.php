<?php

use App\Domain\Attendance\WorkCalendar;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Http\Middleware\EnsureAccountUsable;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\StaffAlert;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    withoutFaceId(); // Phase 3 rules; Face ID is covered in tests/Feature/FaceId.
    Notification::fake();
    at('2026-11-16 08:00');
});

describe('work schedule (days off / remote days)', function () {
    it('is selected once by the employee, then locked', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/me/work-schedule')->assertOk()->assertJsonPath('selected', false)->assertJsonPath('max_days_off_per_week', 2);
        $this->postJson('/api/me/work-schedule', ['days_off' => [3], 'remote_days' => [5]])->assertCreated()
            ->assertJsonPath('locked', true)->assertJsonPath('current', null)
            ->assertJsonPath('upcoming.0.effective_from', '2026-11-17')->assertJsonPath('upcoming.0.days_off', [3])->assertJsonPath('upcoming.0.remote_days', [5]);
        $this->postJson('/api/me/work-schedule', ['days_off' => [2], 'remote_days' => []])->assertStatus(409)->assertJsonPath('code', 'schedule_locked');
        expect(WorkSchedule::query()->sole()->days_off)->toBe([3]);
    });

    it('validates the choice against settings', function (array $payload) {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/me/work-schedule', $payload)->assertStatus(422);
    })->with([
        'too many days off' => [['days_off' => [1, 2, 3], 'remote_days' => []]],
        'non working day' => [['days_off' => [6], 'remote_days' => []]],
        'bad weekday' => [['days_off' => [9], 'remote_days' => []]],
        'overlap' => [['days_off' => [2], 'remote_days' => [2]]],
        'duplicates' => [['days_off' => [2, 2], 'remote_days' => []]],
        'missing' => [[]],
    ]);

    it('is changed only by admins holding the tier, from an effective date, without rewriting the past', function () {
        $user = User::factory()->create();
        WorkSchedule::query()->create(['user_id' => $user->id, 'effective_from' => '2026-11-01', 'days_off' => [3], 'remote_days' => []]);
        Sanctum::actingAs(User::factory()->admin(Permission::ViewTasks)->create());
        $this->putJson("/api/admin/users/{$user->id}/work-schedule", ['days_off' => [], 'remote_days' => []])->assertForbidden();

        $admin = User::factory()->admin(Permission::ManageWorkSchedules)->create();
        Sanctum::actingAs($admin);
        $this->putJson("/api/admin/users/{$user->id}/work-schedule", ['days_off' => [2], 'remote_days' => [4], 'effective_from' => '2026-11-10'])->assertStatus(422);
        $this->putJson("/api/admin/users/{$user->id}/work-schedule", ['days_off' => [2], 'remote_days' => [4], 'effective_from' => '2026-11-23'])->assertOk()
            ->assertJsonPath('current.days_off', [3])->assertJsonPath('upcoming.0.days_off', [2]);
        $this->putJson("/api/admin/users/{$admin->id}/work-schedule", ['days_off' => [], 'remote_days' => []])->assertForbidden();

        $calendar = app(WorkCalendar::class);
        expect($calendar->dayStatus($user, '2026-11-18')->value)->toBe('day_off')
            ->and($calendar->dayStatus($user, '2026-11-25')->value)->toBe('working')
            ->and($calendar->dayStatus($user, '2026-11-26')->value)->toBe('remote')
            ->and(ActivityLog::query()->where('event', 'schedule.updated')->where('actor_id', $admin->id)->exists())->toBeTrue();
    });
});

describe('holidays', function () {
    it('are readable by everyone and managed by super admins and manage_leaves admins', function () {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/admin/holidays', ['date' => '2026-12-25', 'name' => 'Christmas'])->assertForbidden();
        Sanctum::actingAs(User::factory()->admin(Permission::ViewLateness)->create());
        $this->postJson('/api/admin/holidays', ['date' => '2026-12-25', 'name' => 'Christmas'])->assertForbidden();

        Sanctum::actingAs(User::factory()->admin(Permission::ManageLeaves)->create());
        $id = $this->postJson('/api/admin/holidays', ['date' => '2026-12-25', 'name' => 'Christmas'])->assertCreated()->json('data.id');
        $this->postJson('/api/admin/holidays', ['date' => '2026-12-25', 'name' => 'Again'])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/holidays?year=2026')->assertOk()->assertJsonPath('data.0.name', 'Christmas');

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->deleteJson("/api/admin/holidays/$id")->assertNoContent();
        expect(Holiday::query()->count())->toBe(0);
    });
});

describe('leaves', function () {
    it('are declared by manage_leaves admins for employees only, without overlap', function () {
        $user = User::factory()->create();
        Sanctum::actingAs(User::factory()->admin(Permission::ViewLateness)->create());
        $this->postJson("/api/admin/users/{$user->id}/leaves", ['start_date' => '2026-11-20', 'end_date' => '2026-11-24'])->assertForbidden();
        $this->getJson('/api/admin/leaves')->assertForbidden();

        $admin = User::factory()->admin(Permission::ManageLeaves)->create();
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$user->id}/leaves", ['start_date' => '2026-11-24', 'end_date' => '2026-11-20'])->assertStatus(422);
        $id = $this->postJson("/api/admin/users/{$user->id}/leaves", ['start_date' => '2026-11-20', 'end_date' => '2026-11-24', 'note' => 'Annual'])->assertCreated()->json('data.id');
        $this->postJson("/api/admin/users/{$user->id}/leaves", ['start_date' => '2026-11-24', 'end_date' => '2026-11-26'])->assertStatus(422)->assertJsonPath('code', 'leave_overlap');
        $this->postJson("/api/admin/users/{$admin->id}/leaves", ['start_date' => '2026-12-01', 'end_date' => '2026-12-02'])->assertForbidden();
        $this->getJson("/api/admin/leaves?user_id={$user->id}")->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($user);
        $this->getJson('/api/me/leaves')->assertOk()->assertJsonPath('data.0.start_date', '2026-11-20')->assertJsonMissingPath('data.0.created_by');

        Sanctum::actingAs($admin);
        $this->deleteJson("/api/admin/leaves/$id")->assertNoContent();
        expect(Leave::query()->count())->toBe(0);
    });
});

describe('admin check-in on behalf', function () {
    it('requires the tier and a reason, targets employees only, and is logged', function () {
        $user = User::factory()->create();
        Sanctum::actingAs(User::factory()->admin(Permission::ViewLateness)->create());
        $this->postJson("/api/admin/users/{$user->id}/check-in", ['reason' => 'Phone broken'])->assertForbidden();

        $admin = User::factory()->admin(Permission::CheckInForEmployee)->create();
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$user->id}/check-in", [])->assertStatus(422);
        at('2026-11-16 08:30');
        $this->postJson("/api/admin/users/{$user->id}/check-in", ['reason' => 'Phone broken'])->assertCreated()
            ->assertJsonPath('data.check_in_type', 'admin_on_behalf')->assertJsonPath('data.checked_in_by', $admin->id)
            ->assertJsonMissingPath('data.is_late');
        expect(Attendance::query()->sole()->is_late)->toBeTrue();
        $this->postJson("/api/admin/users/{$user->id}/check-in", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'already_checked_in');
        $this->postJson("/api/admin/users/{$admin->id}/check-in", ['reason' => 'Self'])->assertForbidden();
        expect(ActivityLog::query()->where('event', 'attendance.admin_check_in')->where('actor_id', $admin->id)->where('user_id', $user->id)->exists())->toBeTrue();
    });

    it('refuses inactive accounts', function () {
        $user = User::factory()->status(UserStatus::Blocked)->create();
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->postJson("/api/admin/users/{$user->id}/check-in", ['reason' => 'Test'])->assertStatus(422);
    });
});

describe('missions', function () {
    beforeEach(function () {
        $this->employee = User::factory()->create();
        Sanctum::actingAs($this->employee);
        $id = $this->postJson('/api/attendance/check-in/intent', ['type' => 'mission', 'reason' => 'Client visit'])->json('intent_id');
        $this->attendanceId = $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->json('data.id');
    });

    it('are listed and approved only by approvers, once', function () {
        Sanctum::actingAs(User::factory()->admin(Permission::ViewLateness)->create());
        $this->getJson('/api/admin/attendance/missions')->assertForbidden();
        $this->postJson("/api/admin/attendance/{$this->attendanceId}/mission/approve")->assertForbidden();

        $approver = User::factory()->admin(Permission::ApproveMissionCheckins)->create();
        Sanctum::actingAs($approver);
        $this->getJson('/api/admin/attendance/missions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.id', $this->employee->id);
        $this->postJson("/api/admin/attendance/{$this->attendanceId}/mission/approve", ['note' => 'OK'])->assertOk()
            ->assertJsonPath('data.mission_status', 'approved')->assertJsonPath('data.mission_validated_by', $approver->id);
        $this->postJson("/api/admin/attendance/{$this->attendanceId}/mission/reject")->assertStatus(409)->assertJsonPath('code', 'mission_already_reviewed');
        $this->getJson('/api/admin/attendance/missions')->assertJsonCount(0, 'data');
        Notification::assertSentTo($this->employee, StaffAlert::class, fn ($n) => $n->kind === 'mission_approved');
    });

    it('can be rejected', function () {
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->postJson("/api/admin/attendance/{$this->attendanceId}/mission/reject")->assertOk()->assertJsonPath('data.mission_status', 'rejected');
    });

    it('approval of a non-mission attendance is a 404', function () {
        $other = User::factory()->create();
        gpsCheckIn($this, $other);
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $gpsId = Attendance::query()->where('user_id', $other->id)->value('id');
        $this->postJson("/api/admin/attendance/{$gpsId}/mission/approve")->assertNotFound();
    });
});

describe('admin views per tier', function () {
    beforeEach(function () {
        $this->late = User::factory()->create(['first_name' => 'Ama']);
        $this->absent = User::factory()->create(['first_name' => 'Kofi']);
        at('2026-11-16 08:20');
        gpsCheckIn($this, $this->late);
    });

    it('is forbidden without an attendance tier', function () {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/attendance/day')->assertForbidden();
        Sanctum::actingAs(User::factory()->admin(Permission::ViewTasks)->create());
        $this->getJson('/api/admin/attendance/day')->assertForbidden();
        $this->getJson("/api/admin/users/{$this->late->id}/attendance")->assertForbidden();
        $this->getJson('/api/admin/attendance/attempts')->assertForbidden();
    });

    it('shows lateness only to view_lateness holders and coordinates only to super admins', function () {
        Sanctum::actingAs(User::factory()->admin(Permission::ViewActivities)->create());
        $r = $this->getJson('/api/admin/attendance/day?date=2026-11-16')->assertOk()->assertJsonCount(2, 'data');
        expect($r->json('data.0.user.first_name'))->toBe('Ama')
            ->and($r->json('data.0.attendance'))->not->toHaveKey('is_late')
            ->and($r->json('data.0.attendance'))->not->toHaveKey('check_in_position')
            ->and($r->json('data.1.attendance'))->toBeNull()
            ->and($r->json('data.1.expected'))->toBeTrue()
            ->and($r->json('data.0.user'))->not->toHaveKey('email');

        Sanctum::actingAs(User::factory()->admin(Permission::ViewLateness)->create());
        $this->getJson('/api/admin/attendance/day')->assertJsonPath('data.0.attendance.is_late', true)->assertJsonMissingPath('data.0.attendance.check_in_position');
        $this->getJson("/api/admin/users/{$this->late->id}/attendance")->assertOk()->assertJsonPath('data.0.is_late', true);

        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson('/api/admin/attendance/day')->assertJsonPath('data.0.attendance.check_in_position.lat', OFFICE_LAT);
    });

    it('lists refused attempts to view_lateness holders', function () {
        gpsCheckIn($this, $this->absent, OFFICE_LAT + 0.01)->assertStatus(422);
        Sanctum::actingAs(User::factory()->admin(Permission::ViewLateness)->create());
        $this->getJson('/api/admin/attendance/attempts?date=2026-11-16')->assertOk()->assertJsonPath('data.0.outcome', 'out_of_perimeter')
            ->assertJsonMissingPath('data.0.lat');
    });

    it('never lets an admin view staff attendance histories', function () {
        $other = User::factory()->admin()->create();
        Sanctum::actingAs(User::factory()->admin(Permission::ViewLateness)->create());
        $this->getJson("/api/admin/users/{$other->id}/attendance")->assertForbidden();
    });
});

describe('access conditional on check-in', function () {
    beforeEach(function () {
        Route::middleware(['api', 'auth:sanctum', EnsureAccountUsable::class, 'checked-in'])
            ->get('/api/_test/tasks', fn () => response()->json(['ok' => true]));
    });

    it('blocks employees until they check in today', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/_test/tasks')->assertForbidden()->assertJsonPath('code', 'checkin_required');
        $this->getJson('/api/me')->assertOk();
        $this->getJson('/api/notifications')->assertOk();
        gpsCheckIn($this, $user);
        $this->getJson('/api/_test/tasks')->assertOk();
        at('2026-11-17 08:00');
        $this->getJson('/api/_test/tasks')->assertForbidden();
    });

    it('does not block on days off, leave, holidays, non-working days, nor staff', function () {
        $user = User::factory()->create();
        WorkSchedule::query()->create(['user_id' => $user->id, 'effective_from' => '2026-11-01', 'days_off' => [1], 'remote_days' => []]);
        Leave::query()->create(['user_id' => $user->id, 'start_date' => '2026-11-17', 'end_date' => '2026-11-17']);
        Holiday::query()->create(['date' => '2026-11-18', 'name' => 'H']);
        Sanctum::actingAs($user);
        foreach (['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-21'] as $d) {
            at("$d 09:00");
            $this->getJson('/api/_test/tasks')->assertOk();
        }
        at('2026-11-19 09:00');
        $this->getJson('/api/_test/tasks')->assertForbidden();
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/_test/tasks')->assertOk();
    });

    it('accepts a pending mission check-in', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/attendance/check-in/intent', ['type' => 'mission', 'reason' => 'Client'])->json('intent_id');
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id]);
        $this->getJson('/api/_test/tasks')->assertOk();
    });
});

it('rate limits check-in requests per user from the setting', function () {
    app(SettingsService::class)->set(['checkin_attempts_per_minute' => 3]);
    Sanctum::actingAs(User::factory()->create());
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => 0, 'longitude' => 0, 'accuracy' => 5])->assertStatus(422);
    }
    $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => 0, 'longitude' => 0, 'accuracy' => 5])->assertStatus(429);
});
