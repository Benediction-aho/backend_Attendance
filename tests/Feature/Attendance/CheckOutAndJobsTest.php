<?php

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Jobs\SendWebPush;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Leave;
use App\Models\ScheduledRun;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\CheckinReminder;
use App\Notifications\StaffAlert;
use App\Settings\SettingsService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    withoutFaceId(); // Phase 3 rules; Face ID is covered in tests/Feature/FaceId.
    Notification::fake();
    at('2026-11-16 08:00');
});

describe('check-out', function () {
    it('needs a check-in first', function () {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/attendance/check-out')->assertStatus(409)->assertJsonPath('code', 'not_checked_in');
    });

    it('records the distance to the office from anywhere after the end of the day', function () {
        $user = User::factory()->create();
        gpsCheckIn($this, $user);
        at('2026-11-16 17:05');
        $r = $this->postJson('/api/attendance/check-out', ['latitude' => OFFICE_LAT + 0.029, 'longitude' => OFFICE_LNG, 'accuracy' => 20])->assertOk()
            ->assertJsonPath('data.check_out_type', 'manual')
            ->assertJsonPath('data.early_leave', false)
            ->assertJsonPath('data.check_out_at_office', false)
            ->assertJsonPath('data.check_out_position_available', true);
        expect($r->json('data.check_out_distance_m'))->toBeGreaterThan(3000)->toBeLessThan(3400);
        $this->postJson('/api/attendance/check-out')->assertStatus(409)->assertJsonPath('code', 'already_checked_out');
    });

    it('accepts a check-out without position ("position not available")', function () {
        $user = User::factory()->create();
        gpsCheckIn($this, $user);
        at('2026-11-16 18:00');
        $this->postJson('/api/attendance/check-out')->assertOk()
            ->assertJsonPath('data.check_out_position_available', false)->assertJsonPath('data.check_out_distance_m', null);
    });

    it('requires a reason before the end of the day and notifies authorized admins', function () {
        $viewer = User::factory()->admin(Permission::ViewLateness)->create();
        $other = User::factory()->admin(Permission::ViewTasks)->create();
        $user = User::factory()->create();
        gpsCheckIn($this, $user);
        at('2026-11-16 16:59:59');
        $this->postJson('/api/attendance/check-out', ['reason' => '  '])->assertStatus(422)->assertJsonPath('code', 'reason_required')->assertJsonPath('end_of_day', '17:00');
        $this->postJson('/api/attendance/check-out', ['reason' => 'Doctor appointment'])->assertOk()
            ->assertJsonPath('data.early_leave', true)->assertJsonPath('data.early_leave_reason', 'Doctor appointment');
        Notification::assertSentTo($viewer, StaffAlert::class, fn ($n) => $n->kind === 'early_checkout');
        Notification::assertNotSentTo($other, StaffAlert::class);
    });

    it('does not ask for a reason when the setting is off', function () {
        app(SettingsService::class)->set(['early_checkout_reason_required' => false]);
        $user = User::factory()->create();
        gpsCheckIn($this, $user);
        $this->postJson('/api/attendance/check-out')->assertOk()->assertJsonPath('data.early_leave', true);
    });

    it('uses the end of day setting', function () {
        app(SettingsService::class)->set(['end_of_day_time' => '16:00']);
        $user = User::factory()->create();
        gpsCheckIn($this, $user);
        at('2026-11-16 16:30');
        $this->postJson('/api/attendance/check-out')->assertOk()->assertJsonPath('data.early_leave', false);
    });
});

describe('automatic check-out at midnight', function () {
    it('closes open attendances at 00:00 as "check-out forgotten", once, and catches up', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();
        gpsCheckIn($this, $a);
        gpsCheckIn($this, $b);
        at('2026-11-16 18:00');
        Sanctum::actingAs($b);
        $this->postJson('/api/attendance/check-out')->assertOk();

        at('2026-11-16 23:59');
        $this->artisan('attendance:auto-checkout')->assertSuccessful();
        expect(Attendance::query()->whereNull('check_out_at')->count())->toBe(1);

        // The server was down at midnight: caught up at 03:17.
        at('2026-11-17 03:17');
        $this->artisan('attendance:auto-checkout')->assertSuccessful();
        $row = Attendance::query()->where('user_id', $a->id)->sole();
        expect($row->check_out_type)->toBe('auto_midnight')
            ->and($row->check_out_at->equalTo(Carbon::parse('2026-11-17 00:00', 'Africa/Accra')))->toBeTrue()
            ->and(Attendance::query()->where('user_id', $b->id)->sole()->check_out_type)->toBe('manual')
            ->and(ScheduledRun::query()->where('run_key', 'auto_checkout:2026-11-16')->sole()->status)->toBe('succeeded');

        $this->artisan('attendance:auto-checkout')->assertSuccessful();
        expect(ScheduledRun::query()->where('task', 'auto_checkout')->count())->toBe(1);

        Sanctum::actingAs($a);
        $this->getJson('/api/attendance/history')->assertJsonPath('data.0.checkout_forgotten', true);
    });

    it('a new day starts fresh after midnight', function () {
        $a = User::factory()->create();
        gpsCheckIn($this, $a);
        at('2026-11-17 00:00:01');
        $this->getJson('/api/attendance/today')->assertJsonPath('work_date', '2026-11-17')->assertJsonPath('checked_in', false);
        $this->postJson('/api/attendance/check-out')->assertStatus(409)->assertJsonPath('code', 'not_checked_in');
        gpsCheckIn($this, $a)->assertCreated()->assertJsonPath('data.work_date', '2026-11-17');
        expect(Attendance::query()->count())->toBe(2);
    });

    it('closes a straggler of an already processed date', function () {
        $a = User::factory()->create();
        at('2026-11-17 00:05');
        $this->artisan('attendance:auto-checkout');
        gpsCheckIn($this, $a);
        Attendance::query()->update(['work_date' => '2026-11-16', 'check_in_at' => Carbon::parse('2026-11-16 09:00', 'Africa/Accra')]);
        ScheduledRun::query()->create(['task' => 'auto_checkout', 'run_key' => 'auto_checkout:2026-11-16', 'status' => 'succeeded', 'started_at' => now()]);
        $this->artisan('attendance:auto-checkout');
        expect(Attendance::query()->sole()->check_out_type)->toBe('auto_midnight');
    });

    it('never sets a check-out before the check-in', function () {
        app(SettingsService::class)->set(['auto_checkout_time' => '23:00'], null, Carbon::parse('2026-11-01'));
        $a = User::factory()->create();
        at('2026-11-16 23:30');
        gpsCheckIn($this, $a);
        $this->artisan('attendance:auto-checkout');
        $row = Attendance::query()->sole();
        expect($row->check_out_at->gte($row->check_in_at))->toBeTrue();
    });
});

describe('check-in reminder', function () {
    it('reminds expected employees without check-in, once, at the reminder time', function () {
        Queue::fake();
        $checked = User::factory()->create();
        $missing = User::factory()->create();
        $dayOff = User::factory()->create();
        $onLeave = User::factory()->create();
        $blocked = User::factory()->status(UserStatus::Blocked)->create();
        $admin = User::factory()->admin()->create();
        WorkSchedule::query()->create(['user_id' => $dayOff->id, 'effective_from' => '2026-11-01', 'days_off' => [1], 'remote_days' => []]);
        Leave::query()->create(['user_id' => $onLeave->id, 'start_date' => '2026-11-16', 'end_date' => '2026-11-16']);
        gpsCheckIn($this, $checked);

        at('2026-11-16 08:04');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Not reminder time.');
        at('2026-11-16 08:05');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Reminded 1 employee(s).');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Already sent today.');

        Notification::assertSentTo($missing, CheckinReminder::class);
        foreach ([$checked, $dayOff, $onLeave, $blocked, $admin] as $u) {
            Notification::assertNotSentTo($u, CheckinReminder::class);
        }
        Queue::assertPushed(SendWebPush::class, 1);
    });

    it('stores the reminder in the notification centre', function () {
        $manager = new ChannelManager(app());
        Notification::swap($manager);
        app()->instance(Dispatcher::class, $manager);
        Queue::fake();
        $missing = User::factory()->create();
        at('2026-11-16 08:05');
        $this->artisan('attendance:checkin-reminder');
        expect($missing->notifications()->sole()->data['kind'])->toBe('checkin_reminder');
    });

    it('catches up a missed run the same day but not after the end of the day', function () {
        Queue::fake();
        User::factory()->create();
        at('2026-11-16 17:00');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Not reminder time.');
        at('2026-11-16 10:40');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Reminded 1 employee(s).');
    });

    it('sends nothing on holidays and non-working days', function () {
        Queue::fake();
        User::factory()->create();
        Holiday::query()->create(['date' => '2026-11-16', 'name' => 'Holiday']);
        at('2026-11-16 08:05');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Reminded 0 employee(s).');
        at('2026-11-21 08:05');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Reminded 0 employee(s).');
    });

    it('uses the reminder time setting', function () {
        Queue::fake();
        app(SettingsService::class)->set(['checkin_reminder_time' => '07:30'], null, Carbon::parse('2026-11-01'));
        User::factory()->create();
        at('2026-11-16 07:31');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Reminded 1 employee(s).');
    });
});

describe('history', function () {
    it('lists only the own attendances, filtered by dates', function () {
        $me = User::factory()->create();
        $other = User::factory()->create();
        foreach (['2026-11-16', '2026-11-17', '2026-11-18'] as $d) {
            at("$d 08:00");
            gpsCheckIn($this, $me);
            gpsCheckIn($this, $other);
        }
        Sanctum::actingAs($me);
        $this->getJson('/api/attendance/history?from=2026-11-17')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.work_date', '2026-11-18')->assertJsonMissingPath('data.0.user_id');
        $this->getJson('/api/attendance/history?from=2026-11-18&to=2026-11-17')->assertStatus(422);
    });
});
