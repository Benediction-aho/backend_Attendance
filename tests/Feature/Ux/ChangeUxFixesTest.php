<?php

use App\Domain\Reports\WeeklyWindow;
use App\Enums\Permission;
use App\Models\User;
use App\Models\WeeklyReportSubmission;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// frontend docs/reports/change-2026-11-14-ux.md – backend findings. 2026-11-16 is a Monday.

beforeEach(function () {
    withoutFaceId();
    Notification::fake();
    Storage::fake('local');
});

function uxcAs(object $test, User $user): void
{
    app('auth')->forgetGuards();
    $test->flushHeaders();
    Sanctum::actingAs($user);
}

describe('UXC-01 no weekly report for a Friday the person was not tracking', function () {
    it('shows not_expected and refuses the upload for the week before an admin started', function () {
        at('2026-11-15 10:00'); // Sunday go-live
        adminsTrackFrom('2026-11-15');
        $admin = User::factory()->admin()->create(['created_at' => '2026-10-01 09:00:00+00']);
        $employee = User::factory()->create(['created_at' => '2026-10-01 09:00:00+00']);
        // The reported case: Sunday of the week 9–13 Nov, before any check-in.
        uxcAs($this, $admin);
        $this->getJson('/api/me/dashboard')->assertOk()->assertJsonPath('data.week.weekly_report.status', 'not_expected')
            ->assertJsonPath('data.week.weekly_report.expected', false);
        uxcAs($this, $employee);
        $this->getJson('/api/me/dashboard')->assertOk()->assertJsonPath('data.week.weekly_report.status', 'open');

        at('2026-11-16 09:00');
        uxcAs($this, $admin);
        attend($admin, '2026-11-16');
        $this->getJson('/api/me/dashboard')->assertOk()->assertJsonPath('data.week.weekly_report.status', 'not_open');
        $this->getJson('/api/me/reports/weekly/submission')->assertOk()->assertJsonPath('data.can_submit', false);
        $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertUnprocessable()->assertJsonPath('code', 'window_not_open');
        // The employee still owes last week's missing report (grace) – unchanged.
        uxcAs($this, $employee);
        attend($employee, '2026-11-16');
        $this->getJson('/api/me/reports/weekly/submission')->assertJsonPath('data.can_submit', true)->assertJsonPath('data.week_start', '2026-11-09');
        expect(WeeklyReportSubmission::query()->count())->toBe(0);
    });

    it('is not_expected on the report day itself when the admin starts after it', function () {
        at('2026-11-20 16:10'); // Friday, window open
        adminsTrackFrom('2026-11-23');
        $admin = User::factory()->admin()->create(['created_at' => '2026-10-01 09:00:00+00']);
        adminsTrackFrom('2026-11-23');
        uxcAs($this, $admin);
        // Not tracking yet: employee routes are closed; the window logic never sees the admin as owing it.
        $this->getJson('/api/me/dashboard')->assertForbidden();
        $window = app(WeeklyWindow::class);
        expect($window->owes($admin, '2026-11-20'))->toBeFalse()
            ->and($window->targetWeek($admin, now()))->toBeNull()
            ->and($window->deadlineFor($admin, '2026-11-20')->format('Y-m-d H:i'))->toBe('2026-11-20 17:00');
    });

    it('does not give the grace to an employee who joined after the Friday', function () {
        at('2026-11-23 09:00');
        $new = User::factory()->create(['created_at' => '2026-11-21 09:00:00+00']);
        uxcAs($this, $new);
        attend($new, '2026-11-23');
        $this->getJson('/api/me/reports/weekly/submission')->assertJsonPath('data.can_submit', false);
        $this->getJson('/api/me/dashboard')->assertJsonPath('data.week.weekly_report.status', 'not_open');
    });
});

it('UXC-05 gives neutral daily report totals per viewer', function () {
    at('2026-11-16 18:00');
    adminsTrackFrom('2026-11-16');
    User::factory()->count(2)->create();
    User::factory()->admin()->create();
    $super = User::factory()->superAdmin()->create();
    $viewer = User::factory()->admin(Permission::ViewReports)->create();
    $this->artisan('reports:daily')->assertSuccessful();

    uxcAs($this, $super);
    $this->getJson('/api/admin/reports/daily')->assertOk()
        ->assertJsonPath('totals', ['people' => 4, 'employees' => 2, 'admins' => 2, 'with_report' => 4]);
    uxcAs($this, $viewer);
    $this->getJson('/api/admin/reports/daily')->assertOk()
        ->assertJsonPath('totals', ['people' => 2, 'employees' => 2, 'admins' => 0, 'with_report' => 2]);
});

it('UXC-07 never reports more days present than expected: a non-working day is an extra day', function () {
    at('2026-11-21 10:00'); // Saturday
    $u = User::factory()->create(['created_at' => '2026-10-01 09:00:00+00']);
    foreach (['2026-11-16', '2026-11-17', '2026-11-21'] as $d) {
        attend($u, $d);
    }
    uxcAs($this, $u);
    $this->getJson('/api/me/dashboard')->assertOk()
        ->assertJsonPath('data.week.presence_days', 2)
        ->assertJsonPath('data.week.expected_days_so_far', 5)
        ->assertJsonPath('data.week.extra_days', 1);

    at('2026-11-22 10:00'); // Sunday of a week with only a weekend check-in
    $v = User::factory()->create(['created_at' => '2026-10-01 09:00:00+00']);
    attend($v, '2026-11-22');
    uxcAs($this, $v);
    $this->getJson('/api/me/dashboard')->assertOk()->assertJsonPath('data.week.presence_days', 0)
        ->assertJsonPath('data.week.expected_days_so_far', 5)->assertJsonPath('data.week.extra_days', 1);
});
