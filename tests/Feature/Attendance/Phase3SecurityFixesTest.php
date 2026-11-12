<?php

use App\Enums\Permission;
use App\Jobs\SendWebPush;
use App\Mail\StaffAlertMail;
use App\Models\Attendance;
use App\Models\ScheduledRun;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\CheckinReminder;
use App\Notifications\StaffAlert;
use App\Services\ScheduledRunner;
use App\Settings\SettingsService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    withoutFaceId(); // Phase 3 rules; Face ID is covered in tests/Feature/FaceId.
    Notification::fake();
    at('2026-11-16 08:00');
});

describe('P3-01 self-selected schedule', function () {
    it('limits remote days with the max_remote_days_per_week setting', function () {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/me/work-schedule')->assertJsonPath('max_remote_days_per_week', 2);
        $this->postJson('/api/me/work-schedule', ['days_off' => [], 'remote_days' => [1, 2, 3, 4, 5]])->assertStatus(422);
        $this->postJson('/api/me/work-schedule', ['days_off' => [], 'remote_days' => [1, 2, 3]])->assertStatus(422);
        $this->postJson('/api/me/work-schedule', ['days_off' => [1, 2, 3], 'remote_days' => []])->assertStatus(422);
        expect(WorkSchedule::query()->count())->toBe(0);
    });

    it('uses the configured value', function () {
        app(SettingsService::class)->set(['max_remote_days_per_week' => 0], null, Carbon::parse('2026-11-01'));
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/me/work-schedule', ['days_off' => [], 'remote_days' => [1]])->assertStatus(422);
        $this->postJson('/api/me/work-schedule', ['days_off' => [2], 'remote_days' => []])->assertCreated();
    });

    it('takes effect from the next work date, never the same day', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->postJson('/api/me/work-schedule', ['days_off' => [], 'remote_days' => [1, 2]])->assertCreated();
        expect(WorkSchedule::query()->sole()->effective_from->toDateString())->toBe('2026-11-17');
        // Monday 16: still a normal on-site day, remote refused.
        $this->postJson('/api/attendance/check-in/intent', ['type' => 'remote'])->assertStatus(422)->assertJsonPath('code', 'not_remote_day');
        at('2026-11-17 08:00');
        $this->postJson('/api/attendance/check-in/intent', ['type' => 'remote'])->assertCreated();
    });

    it('notifies every manage_work_schedules holder', function () {
        $manager = User::factory()->admin(Permission::ManageWorkSchedules)->create();
        $other = User::factory()->admin(Permission::ViewTasks)->create();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/me/work-schedule', ['days_off' => [3], 'remote_days' => [5]])->assertCreated();
        Notification::assertSentTo($manager, StaffAlert::class, fn (StaffAlert $n) => $n->kind === 'schedule_selected' && str_contains($n->body, 'Fri'));
        Notification::assertNotSentTo($other, StaffAlert::class);
    });
});

describe('P3-02 scheduled runs', function () {
    it('retries only the employees not reminded when one send fails', function () {
        Queue::fake();
        $ok = User::factory()->create();
        $bad = User::factory()->create();
        $fake = new class($bad->id) implements Dispatcher
        {
            public array $sent = [];

            public bool $fail = true;

            public function __construct(public int $badId) {}

            public function send($notifiables, $notification) {}

            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                foreach (collect(is_iterable($notifiables) ? $notifiables : [$notifiables]) as $n) {
                    if ($this->fail && $n->id === $this->badId) {
                        throw new RuntimeException('SMTP down');
                    }
                    $this->sent[] = $n->id;
                }
            }
        };
        app()->instance(Dispatcher::class, $fake);

        at('2026-11-16 08:05');
        $this->artisan('attendance:checkin-reminder');
        expect($fake->sent)->toBe([$ok->id])
            ->and(ScheduledRun::query()->sole()->status)->toBe('failed');

        $fake->fail = false;
        at('2026-11-16 08:06');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Reminded 1 employee(s).');
        expect($fake->sent)->toBe([$ok->id, $bad->id])
            ->and(ScheduledRun::query()->sole()->status)->toBe('succeeded');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Already sent today.');
        expect($fake->sent)->toHaveCount(2);
    });

    it('never reminds an employee twice even if the run is retried', function () {
        Queue::fake();
        $u = User::factory()->create();
        DB::table('reminder_deliveries')->insert(['kind' => 'checkin_reminder', 'user_id' => $u->id, 'work_date' => '2026-11-16', 'sent_at' => now()]);
        at('2026-11-16 08:05');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Reminded 0 employee(s).');
        Notification::assertNotSentTo($u, CheckinReminder::class);
        Queue::assertNotPushed(SendWebPush::class);
    });

    it('takes over a stale running run after the configured delay, not before', function () {
        Queue::fake();
        $u = User::factory()->create();
        at('2026-11-16 08:05');
        ScheduledRun::query()->create(['task' => 'checkin_reminder', 'run_key' => 'checkin_reminder:2026-11-16', 'status' => 'running', 'started_at' => now()]);
        at('2026-11-16 08:19');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Already sent today.');
        at('2026-11-16 08:21');
        $this->artisan('attendance:checkin-reminder')->expectsOutput('Reminded 1 employee(s).');
        Notification::assertSentToTimes($u, CheckinReminder::class, 1);
        expect(ScheduledRun::query()->sole()->status)->toBe('succeeded');
    });

    it('takes over a stale run of any task through the shared runner', function () {
        app(SettingsService::class)->set(['scheduled_run_stale_minutes' => 5], null, Carbon::parse('2026-11-01'));
        $runner = app(ScheduledRunner::class);
        at('2026-11-17 00:01');
        ScheduledRun::query()->create(['task' => 'auto_checkout', 'run_key' => 'auto_checkout:2026-11-16', 'status' => 'running', 'started_at' => now()]);
        $calls = 0;
        expect($runner->once('auto_checkout', 'auto_checkout:2026-11-16', null, function () use (&$calls) {
            $calls++;
        }))->toBeFalse();
        at('2026-11-17 00:07');
        expect($runner->once('auto_checkout', 'auto_checkout:2026-11-16', null, function () use (&$calls) {
            $calls++;
        }))->toBeTrue()
            ->and($runner->once('auto_checkout', 'auto_checkout:2026-11-16', null, function () use (&$calls) {
                $calls++;
            }))->toBeFalse()
            ->and($calls)->toBe(1)
            ->and(ScheduledRun::query()->sole()->status)->toBe('succeeded');
    });
});

describe('P3-03 intent bound to device', function () {
    it('refuses confirmation from another device of the same user', function () {
        $user = User::factory()->create();
        $a = deviceToken($user, 'device-test-aaaaaaaaaa');
        $b = deviceToken($user, 'device-test-bbbbbbbbbb');
        $id = $this->withToken($a)->postJson('/api/attendance/check-in/intent', ['type' => 'gps', 'latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])
            ->assertCreated()->json('intent_id');
        $this->app['auth']->forgetGuards();
        $this->withToken($b)->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertStatus(422)->assertJsonPath('code', 'intent_invalid');
        expect(Attendance::query()->count())->toBe(0);
        $this->app['auth']->forgetGuards();
        $this->withToken($a)->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertCreated();
    });
});

describe('P3-07 free text escaped in emails', function () {
    it('escapes a mission reason in the staff alert email', function () {
        $approver = User::factory()->admin(Permission::ApproveMissionCheckins)->create();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $payload = '<img src=x onerror=alert(1)><script>alert(2)</script>';
        $id = $this->postJson('/api/attendance/check-in/intent', ['type' => 'mission', 'reason' => $payload, 'latitude' => 6.0, 'longitude' => -0.5, 'accuracy' => 500])->json('intent_id');
        $this->postJson('/api/attendance/check-in/confirm', ['intent_id' => $id])->assertCreated();
        $alert = null;
        Notification::assertSentTo($approver, StaffAlert::class, function (StaffAlert $n) use (&$alert) {
            $alert = $n;

            return true;
        });
        $html = (new StaffAlertMail($alert->title, $alert->body, $alert->path))->render();
        expect($html)->not->toContain('<img src=x')->not->toContain('<script>')->toContain('&lt;img src=x');
    });

    it('escapes free text in every line of a staff alert email (early leave, names)', function () {
        $html = (new StaffAlertMail('<b>Title</b>', 'Early leave: <a href="http://evil">click</a>', '/admin'))->render();
        expect($html)->not->toContain('<a href="http://evil">')->not->toContain('<b>Title</b>')->toContain('&lt;a href=');
    });
});
