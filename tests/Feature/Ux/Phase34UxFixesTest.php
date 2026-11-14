<?php

use App\Enums\Permission;
use App\Mail\EmailChangeConfirmMail;
use App\Mail\IdentificationCodeMail;
use App\Mail\RecoveryLinkMail;
use App\Mail\StaffAlertMail;
use App\Mail\WeeklyReportsMail;
use App\Models\Attendance;
use App\Models\Leave;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Notifications\StaffAlert;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Notification::fake();
    configureWebauthn();
    config(['app.frontend_url' => 'https://pwa.example.test']);
    at('2026-11-16 07:55');
});

describe('UX34-01 lost passkey: register Face ID again on the same phone', function () {
    it('exposes can_reregister for a ready device and refuses a plain second registration', function () {
        $user = faceIdUser();
        $token = deviceToken($user);
        registerFaceId($this, $token);
        $this->withToken($token)->getJson('/api/me/faceid')->assertOk()
            ->assertJsonPath('state', 'ready')->assertJsonPath('can_reregister', true)->assertJsonPath('replacement_pending', false);
        $this->withToken($token)->postJson('/api/me/faceid/register/options')->assertStatus(409)->assertJsonPath('code', 'faceid_already_registered');
        $this->withToken($token)->postJson('/api/me/faceid/register/options', ['reregister' => 'nope'])->assertStatus(422);
    });

    it('creates a pending credential, keeps the old one until an admin approves, then revokes it', function () {
        $admin = User::factory()->admin(Permission::ApproveFaceIdDevices)->create();
        $user = faceIdUser();
        $token = deviceToken($user);
        registerFaceId($this, $token);
        $old = WebauthnCredential::query()->sole();

        $newAuth = new VirtualAuthenticator; // the phone lost the old passkey
        $options = $this->withToken($token)->postJson('/api/me/faceid/register/options', ['reregister' => true])->assertCreated();
        $this->withToken($token)->postJson('/api/me/faceid/register', [
            'challenge_id' => $options->json('challenge_id'),
            'credential' => $newAuth->create($options->json('public_key')),
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('state', 'ready');

        $new = WebauthnCredential::query()->where('status', 'pending')->sole();
        expect($new->device_id)->toBe($old->device_id)->and($old->fresh()->status)->toBe('approved');
        $this->withToken($token)->getJson('/api/me/faceid')->assertJsonPath('can_reregister', false)->assertJsonPath('replacement_pending', true);

        // Owner alerted again, approvers notified with the same-phone wording.
        Notification::assertSentToTimes($user, StaffAlert::class, 2);
        Notification::assertSentTo($admin, StaffAlert::class, fn (StaffAlert $n) => $n->kind === 'faceid_device_request' && str_contains($n->body, 'same phone'));

        app('auth')->forgetGuards();
        $this->flushHeaders();
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/faceid/credentials')->assertOk()
            ->assertJsonPath('data.0.registration.replaces_same_device', true)
            ->assertJsonPath('data.0.user.id', $user->id);
        $this->postJson("/api/admin/faceid/credentials/{$new->id}/approve")->assertOk();
        expect($old->fresh()->status)->toBe('revoked')->and($new->fresh()->status)->toBe('approved');

        app('auth')->forgetGuards();
        $this->flushHeaders();
        faceIdCheckIn($this, $token, $newAuth)->assertCreated();
    });

    it('a rejected re-registration leaves the old credential in place', function () {
        $user = faceIdUser();
        $token = deviceToken($user);
        registerFaceId($this, $token);
        $options = $this->withToken($token)->postJson('/api/me/faceid/register/options', ['reregister' => true])->assertCreated();
        $this->withToken($token)->postJson('/api/me/faceid/register', [
            'challenge_id' => $options->json('challenge_id'), 'credential' => (new VirtualAuthenticator)->create($options->json('public_key')),
        ])->assertCreated();
        $pending = WebauthnCredential::query()->where('status', 'pending')->sole();
        app('auth')->forgetGuards();
        $this->flushHeaders();
        Sanctum::actingAs(User::factory()->admin(Permission::ApproveFaceIdDevices)->create());
        $this->postJson("/api/admin/faceid/credentials/{$pending->id}/reject")->assertOk();
        expect(WebauthnCredential::query()->where('status', 'approved')->count())->toBe(1);
    });
});

describe('UX34-05 email links use PWA routes', function () {
    it('prefixes app-relative paths with /app', function (string $path, string $expected) {
        $html = (new StaffAlertMail('T', 'B', $path))->render();
        expect($html)->toContain('href="'.$expected.'"');
    })->with([
        ['/admin/users/43', 'https://pwa.example.test/app/admin/users/43'],
        ['/admin/faceid-devices', 'https://pwa.example.test/app/admin/faceid-devices'],
        ['/attendance', 'https://pwa.example.test/app/attendance'],
        ['/app/admin/requests', 'https://pwa.example.test/app/admin/requests'],
    ]);

    it('every staff alert path sent by the application resolves under /app', function () {
        $user = faceIdUser();
        registerFaceId($this, deviceToken($user));
        app('auth')->forgetGuards();
        $this->flushHeaders();
        registerFaceId($this, deviceToken($user, 'device-test-0000000002'));
        Notification::assertSentTo($user, StaffAlert::class, function (StaffAlert $n) use ($user) {
            $mail = $n->toMail($user);
            expect($mail)->toBeInstanceOf(StaffAlertMail::class)->and($mail->render())->toContain('https://pwa.example.test/app/');

            return true;
        });
    });

    it('weekly reports email and account emails point to existing routes', function () {
        expect((new WeeklyReportsMail('T', ['x'], [], false))->render())->toContain('https://pwa.example.test/app/admin/reports/weekly')
            ->and((new IdentificationCodeMail('Ama', '123456', now()->addHour(), false))->render())->toContain('https://pwa.example.test/sign-in')
            ->and((new RecoveryLinkMail('Ama', 'tok', 15))->render())->toContain('https://pwa.example.test/recover?token=tok')
            ->and((new EmailChangeConfirmMail('Ama', 'tok', 'a@b.c', 15, false))->render())->toContain('https://pwa.example.test/confirm-email?token=tok');
    });

    it('no mail or notification path in the code base points outside the PWA route table', function () {
        $files = array_merge(glob(app_path('Mail/*.php')), glob(app_path('Services/*.php')), glob(app_path('Services/*/*.php')), glob(app_path('Domain/*/*.php')), glob(app_path('Http/Controllers/*/*.php')), glob(app_path('Jobs/*.php')), glob(app_path('Console/Commands/*.php')));
        foreach ($files as $f) {
            $src = (string) file_get_contents($f);
            expect($src)->not->toContain("frontendUrl('/admin")->not->toContain("frontendUrl('/attendance")->not->toContain("frontendUrl('/login'");
        }
    });
});

describe('UX34-04 admin lists carry a disambiguator', function () {
    it('daily attendance, missions, attempts, leaves and Face ID list include email and job title', function () {
        $a = User::factory()->create(['first_name' => 'Ama', 'last_name' => 'Mensah', 'job_title' => 'Officer']);
        User::factory()->create(['first_name' => 'Ama', 'last_name' => 'Mensah', 'job_title' => 'Officer']);
        Leave::query()->create(['user_id' => $a->id, 'start_date' => '2026-11-20', 'end_date' => '2026-11-21']);
        withoutFaceId();
        gpsCheckIn($this, $a, OFFICE_LAT + 0.01)->assertStatus(422);
        app('auth')->forgetGuards();
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $day = $this->getJson('/api/admin/attendance/day')->assertOk()->json('data');
        expect(collect($day)->pluck('user.email')->filter()->unique()->count())->toBe(2)
            ->and($day[0]['user'])->toHaveKeys(['email', 'job_title']);
        $this->getJson('/api/admin/leaves')->assertOk()->assertJsonPath('data.0.user.email', $a->email);
        $this->getJson('/api/admin/attendance/attempts')->assertOk()->assertJsonPath('data.0.user.email', $a->email);
    });

    it('employees never receive other employees\' emails', function () {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/attendance/day')->assertForbidden();
        $this->getJson('/api/admin/leaves')->assertForbidden();
    });
});

describe('UX34-02 confirm returns the today payload', function () {
    it('confirm and check-out include the same body as GET /attendance/today', function () {
        $user = faceIdUser();
        $token = deviceToken($user);
        $auth = registerFaceId($this, $token);
        $r = faceIdCheckIn($this, $token, $auth)->assertCreated()
            ->assertJsonPath('today.checked_in', true)->assertJsonPath('today.checked_out', false)
            ->assertJsonPath('today.available_check_ins', [])->assertJsonPath('today.faceid_state', 'ready');
        expect($r->json('today.attendance.id'))->toBe($r->json('data.id'));
        $today = $this->withToken($token)->getJson('/api/attendance/today')->json();
        expect(array_keys($r->json('today')))->toEqual(array_keys($today))
            ->and($r->json('today.attendance'))->toEqual($today['attendance']);

        at('2026-11-16 17:30');
        $this->withToken($token)->postJson('/api/attendance/check-out', ['latitude' => OFFICE_LAT, 'longitude' => OFFICE_LNG, 'accuracy' => 5])
            ->assertOk()->assertJsonPath('today.checked_out', true);
        expect(Attendance::query()->count())->toBe(1);
    });
});

it('UX34-04: admins without the Users permission get job title and type but not the email', function () {
    User::factory()->create(['first_name' => 'Ama', 'last_name' => 'Mensah', 'job_title' => 'Officer']);
    Sanctum::actingAs(User::factory()->admin(Permission::ViewActivities)->create());
    $r = $this->getJson('/api/admin/attendance/day')->assertOk();
    expect($r->json('data.0.user'))->toHaveKeys(['id', 'job_title', 'employee_type'])->not->toHaveKey('email');
});
