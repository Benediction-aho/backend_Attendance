<?php

use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// docs/reports/change-2026-11-14-security.md (owner decisions 2026-11-15).

beforeEach(function () {
    withoutFaceId();
    Notification::fake();
    Storage::fake('local');
    at('2026-11-16 08:00');
});

function chgAs(object $test, User $user): void
{
    app('auth')->forgetGuards();
    $test->flushHeaders();
    Sanctum::actingAs($user);
}

describe('CHG-02 holidays changed by an admin', function () {
    it('alert every active super admin (in-app + e-mail) and are logged', function () {
        $super = User::factory()->superAdmin()->create();
        $other = User::factory()->admin(Permission::ManageLeaves)->create();
        $admin = User::factory()->admin(Permission::ManageLeaves)->create(['first_name' => 'Adam', 'last_name' => 'Admin']);
        chgAs($this, $admin);
        $id = $this->postJson('/api/admin/holidays', ['date' => '2026-11-16', 'name' => 'Admin day www.x.com'])->assertCreated()->json('data.id');
        Notification::assertSentTo($super, StaffAlert::class, fn (StaffAlert $n, array $via) => $n->kind === 'holiday_changed'
            && $via === ['database', 'mail'] && $n->title === 'Public holiday added by an admin'
            && str_contains($n->body, 'Adam Admin added the public holiday of 2026-11-16') && ! str_contains($n->body, 'www.x'));
        Notification::assertNotSentTo([$other, $admin], StaffAlert::class);

        $this->deleteJson("/api/admin/holidays/{$id}")->assertNoContent();
        Notification::assertSentTo($super, StaffAlert::class, fn (StaffAlert $n) => $n->title === 'Public holiday removed by an admin');
        expect(ActivityLog::query()->whereIn('event', ['holiday.created', 'holiday.deleted'])->where('actor_id', $admin->id)->count())->toBe(2);
    });

    it('send no alert when a super admin changes them', function () {
        $super = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create();
        chgAs($this, $super);
        $id = $this->postJson('/api/admin/holidays', ['date' => '2026-11-17', 'name' => 'Day'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/admin/holidays/{$id}")->assertNoContent();
        Notification::assertNothingSent();
    });
});

describe('CHG-04 start date emptied during the day', function () {
    it('keeps check-out, history and own reports for an admin already checked in that day, nothing else', function () {
        adminsTrackFrom('2026-11-16');
        $in = User::factory()->admin()->create();
        $out = User::factory()->admin()->create();
        gpsCheckIn($this, $in)->assertCreated();

        at('2026-11-16 10:00');
        adminsTrackFrom(null);
        chgAs($this, $in);
        $this->getJson('/api/attendance/today')->assertOk()->assertJsonPath('checked_in', true);
        $this->getJson('/api/attendance/history')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/me/reports/activity')->assertOk();
        $this->postJson('/api/attendance/check-out', ['reason' => 'Leaving'])->assertOk();
        chgAs($this, $out);
        $this->getJson('/api/attendance/today')->assertForbidden()->assertJsonPath('code', 'employees_only');

        // The next day the setting applies to both.
        at('2026-11-17 08:00');
        chgAs($this, $in);
        $this->getJson('/api/attendance/today')->assertForbidden()->assertJsonPath('code', 'employees_only');
        expect($in->tracksAttendance('2026-11-16'))->toBeTrue()->and($in->tracksAttendance())->toBeFalse();
    });
});
