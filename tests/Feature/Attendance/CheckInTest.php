<?php

use App\Enums\Permission;
use App\Models\Attendance;
use App\Models\CheckinAttempt;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\StaffAlert;
use App\Settings\SettingsService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    withoutFaceId(); // Phase 3 rules; Face ID is covered in tests/Feature/FaceId.
    Notification::fake();
    at('2026-11-16 07:55');
});

it('requires authentication on every attendance route', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized();
})->with([
    ['GET', '/api/attendance/today'], ['GET', '/api/attendance/history'],
    ['POST', '/api/attendance/check-in/intent'], ['POST', '/api/attendance/check-in/confirm'], ['POST', '/api/attendance/check-out'],
]);

it('is reserved to employees', function (string $who) {
    Sanctum::actingAs($who === 'admin' ? User::factory()->admin(...Permission::cases())->create() : User::factory()->superAdmin()->create());
    $this->getJson('/api/attendance/today')->assertForbidden()->assertJsonPath('code', 'employees_only');
    $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])->assertForbidden();
})->with(['admin', 'super_admin']);

it('returns today status with the server time and rules from settings', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/attendance/today')->assertOk()
        ->assertJsonPath('work_date', '2026-11-16')
        ->assertJsonPath('timezone', 'Africa/Accra')
        ->assertJsonPath('server_time', Carbon::now()->setTimezone('Africa/Accra')->toIso8601String())
        ->assertJsonPath('day_status', 'working')
        ->assertJsonPath('expected', true)
        ->assertJsonPath('checked_in', false)
        ->assertJsonPath('available_check_ins', ['gps', 'mission'])
        ->assertJsonPath('rules.radius_m', 50)
        ->assertJsonPath('rules.late_after', '08:10')
        ->assertJsonMissingPath('rules.office_latitude');
});

it('checks in inside the perimeter, on time, with the server time', function () {
    $user = User::factory()->create();
    $response = gpsCheckIn($this, $user, OFFICE_LAT + 0.0002)->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('data.check_in_type', 'gps')
        ->assertJsonPath('data.is_late', false)
        ->assertJsonPath('data.late_after', '08:10')
        ->assertJsonPath('data.work_date', '2026-11-16');
    expect($response->json('data.check_in_distance_m'))->toBeGreaterThan(15)->toBeLessThan(30)
        ->and($response->json('data.check_in_at'))->toBe(Carbon::now()->setTimezone('Africa/Accra')->toIso8601String());
    $this->getJson('/api/attendance/today')->assertJsonPath('checked_in', true)->assertJsonPath('available_check_ins', []);
});

it('ignores any time or date sent by the client', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $intent = $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5, 'check_in_at' => '2026-11-16T07:00:00Z', 'work_date' => '2026-11-10']);
    $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $intent->json('intent_id'), 'check_in_at' => '2026-11-16T07:00:00Z'])
        ->assertCreated()->assertJsonPath('data.work_date', '2026-11-16');
    expect(Attendance::query()->first()->check_in_at->equalTo(Carbon::now()))->toBeTrue();
});

it('refuses outside the perimeter with the distance and records the attempt', function () {
    $user = User::factory()->create();
    gpsCheckIn($this, $user, OFFICE_LAT + 0.0009)->assertStatus(422)
        ->assertJsonPath('code', 'out_of_perimeter')
        ->assertJsonPath('radius_m', 50);
    $attempt = CheckinAttempt::query()->sole();
    expect($attempt->outcome)->toBe('out_of_perimeter')->and((float) $attempt->distance_m)->toBeGreaterThan(95)
        ->and(Attendance::query()->count())->toBe(0);
});

it('refuses a position that is not precise enough', function () {
    $user = User::factory()->create();
    gpsCheckIn($this, $user, accuracy: 80)->assertStatus(422)
        ->assertJsonPath('code', 'low_accuracy')->assertJsonPath('max_accuracy_m', 50);
    expect(CheckinAttempt::query()->sole()->outcome)->toBe('low_accuracy');
});

it('uses the radius and accuracy settings', function () {
    app(SettingsService::class)->set(['checkin_radius_m' => 150, 'checkin_max_accuracy_m' => 100]);
    $user = User::factory()->create();
    gpsCheckIn($this, $user, OFFICE_LAT + 0.0009, accuracy: 80)->assertCreated();
});

it('requires a position for a GPS check-in', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps'])->assertStatus(422);
    $this->postJson('/api/attendance/check-in/intent', ['type' => 'wifi'])->assertStatus(422);
});

describe('lateness', function () {
    it('is late from the late_after minute onwards', function (string $time, bool $late) {
        at("2026-11-16 $time");
        gpsCheckIn($this, User::factory()->create())->assertCreated()->assertJsonPath('data.is_late', $late);
    })->with([['08:09:59', false], ['08:10:00', true], ['10:30', true], ['06:00', false]]);

    it('keeps the rule used when the setting changes later', function () {
        at('2026-11-16 08:12');
        $user = User::factory()->create();
        gpsCheckIn($this, $user)->assertJsonPath('data.is_late', true);
        app(SettingsService::class)->set(['late_after' => '08:30']);
        $a = Attendance::query()->sole();
        expect($a->is_late)->toBeTrue()->and($a->late_after)->toBe('08:10');
    });

    it('applies a future rule only from its effective date', function () {
        at('2026-11-16 06:00');
        app(SettingsService::class)->set(['late_after' => '08:30'], null, Carbon::parse('2026-11-17 00:00', 'Africa/Accra'));
        at('2026-11-16 08:15');
        gpsCheckIn($this, $a = User::factory()->create())->assertJsonPath('data.is_late', true)->assertJsonPath('data.late_after', '08:10');
        at('2026-11-17 08:15');
        gpsCheckIn($this, $a)->assertJsonPath('data.is_late', false)->assertJsonPath('data.late_after', '08:30');
    });

    it('is never late on a day the employee is not expected', function () {
        at('2026-11-21 10:00'); // Saturday
        gpsCheckIn($this, User::factory()->create())->assertCreated()
            ->assertJsonPath('data.is_late', false)->assertJsonPath('data.day_status', 'non_working');
    });
});

describe('double submit and concurrency', function () {
    it('confirming the same intent twice returns the same attendance', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])->json('intent_id');
        $first = $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertCreated();
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertOk()
            ->assertJsonPath('created', false)->assertJsonPath('data.id', $first->json('data.id'));
        expect(Attendance::query()->count())->toBe(1);
    });

    it('two intents confirmed one after the other never duplicate', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $p = ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5];
        $a = $this->postJson('/api/attendance/check-in/intent', $p)->json('intent_id');
        $b = $this->postJson('/api/attendance/check-in/intent', $p)->json('intent_id');
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $a])->assertCreated();
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $b])->assertOk()->assertJsonPath('created', false);
        expect(Attendance::query()->count())->toBe(1);
    });

    it('an admin check-in arriving with the employee check-in never creates a duplicate nor a 500', function () {
        $user = User::factory()->create();
        $admin = User::factory()->admin(Permission::CheckInForEmployee)->create();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])->json('intent_id');
        // The admin writes first (between the employee's intent and confirmation).
        Sanctum::actingAs($admin);
        $this->postJson("/api/admin/users/{$user->id}/check-in", ['reason' => 'Phone broken'])->assertCreated();
        Sanctum::actingAs($user);
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertOk()
            ->assertJsonPath('created', false)->assertJsonPath('data.check_in_type', 'admin_on_behalf');
        expect(Attendance::query()->count())->toBe(1);
    });

    it('the unique constraint rejects a second row for the same user and date', function () {
        $user = User::factory()->create();
        gpsCheckIn($this, $user)->assertCreated();
        expect(fn () => Attendance::query()->create(Attendance::query()->first()->only([
            'user_id', 'work_date', 'day_status', 'check_in_type', 'check_in_at', 'late_after',
        ])))->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('intents', function () {
    it('expires after the configured delay', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])->json('intent_id');
        Carbon::setTestNow(Carbon::now()->addSeconds(121));
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertStatus(422)->assertJsonPath('code', 'intent_invalid');
    });

    it('belongs to its user only', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])->json('intent_id');
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertStatus(422)->assertJsonPath('code', 'intent_invalid');
        expect(Attendance::query()->count())->toBe(0);
    });

    it('is refused once checked in', function () {
        $user = User::factory()->create();
        gpsCheckIn($this, $user)->assertCreated();
        $this->postJson('/api/attendance/check-in/intent', ['type' => 'mission', 'reason' => 'Client'])->assertStatus(409)->assertJsonPath('code', 'already_checked_in');
    });

    it('cannot be confirmed after midnight for the previous day', function () {
        at('2026-11-16 23:59:30');
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $id = $this->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])->json('intent_id');
        at('2026-11-17 00:00:10');
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertStatus(422);
        expect(Attendance::query()->count())->toBe(0);
    });

    // Face ID enforcement (phase 4) is covered in tests/Feature/FaceId/FaceIdTest.php.
});

describe('remote and mission', function () {
    it('allows a remote check-in only on a remote day, without position', function () {
        $user = User::factory()->create();
        WorkSchedule::query()->create(['user_id' => $user->id, 'effective_from' => '2026-11-01', 'days_off' => [3], 'remote_days' => [1]]);
        Sanctum::actingAs($user);
        $this->getJson('/api/attendance/today')->assertJsonPath('day_status', 'remote')->assertJsonPath('available_check_ins', ['gps', 'mission', 'remote']);
        at('2026-11-16 08:20');
        $id = $this->postJson('/api/attendance/check-in/intent', ['type' => 'remote'])->assertCreated()->json('intent_id');
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertCreated()
            ->assertJsonPath('data.check_in_type', 'remote')->assertJsonPath('data.is_late', true);

        at('2026-11-17 08:00');
        $this->postJson('/api/attendance/check-in/intent', ['type' => 'remote'])->assertStatus(422)->assertJsonPath('code', 'not_remote_day');
    });

    it('allows a mission check-in from anywhere with a reason, pending approval, and notifies approvers', function () {
        $approver = User::factory()->admin(Permission::ApproveMissionCheckins)->create();
        $other = User::factory()->admin(Permission::ViewTasks)->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/attendance/check-in/intent', ['type' => 'mission'])->assertStatus(422);
        $id = $this->postJson('/api/attendance/check-in/intent', ['type' => 'mission', 'reason' => 'Visit at client X', 'latitude' => 6.0, 'longitude' => -0.5, 'accuracy' => 500])->assertCreated()->json('intent_id');
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertCreated()
            ->assertJsonPath('data.check_in_type', 'mission')->assertJsonPath('data.mission_status', 'pending')
            ->assertJsonPath('data.reason', 'Visit at client X');
        Notification::assertSentTo($approver, StaffAlert::class, fn ($n) => $n->kind === 'mission_checkin');
        Notification::assertNotSentTo($other, StaffAlert::class);
    });
});

describe('day status', function () {
    it('reports holidays, leave, days off and non-working days', function () {
        $user = User::factory()->create();
        WorkSchedule::query()->create(['user_id' => $user->id, 'effective_from' => '2026-11-01', 'days_off' => [3], 'remote_days' => []]);
        Holiday::query()->create(['date' => '2026-11-19', 'name' => 'Test holiday']);
        Leave::query()->create(['user_id' => $user->id, 'start_date' => '2026-11-20', 'end_date' => '2026-11-24']);
        Sanctum::actingAs($user);
        foreach (['2026-11-18' => 'day_off', '2026-11-19' => 'holiday', '2026-11-20' => 'leave', '2026-11-22' => 'leave', '2026-11-26' => 'working', '2026-11-28' => 'non_working'] as $date => $status) {
            at("$date 09:00");
            $this->getJson('/api/attendance/today')->assertJsonPath('day_status', $status)->assertJsonPath('expected', $status === 'working');
        }
    });

    it('uses the working days setting', function () {
        app(SettingsService::class)->set(['working_days' => [1, 2, 3, 4, 5, 6]]);
        Sanctum::actingAs(User::factory()->create());
        at('2026-11-21 09:00');
        $this->getJson('/api/attendance/today')->assertJsonPath('day_status', 'working');
    });
});
