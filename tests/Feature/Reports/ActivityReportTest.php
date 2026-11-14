<?php

use App\Enums\Permission;
use App\Models\ActivityLog;
use App\Models\ActivityReport;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Settings\SettingDefinitions;
use App\Settings\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * Activity reports (owner request 2026-11-14): a PDF with a title sent at any time by someone who tracks
 * attendance; read by the people who may read that person's weekly reports. 2026-11-17 is a Tuesday.
 */

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    at('2026-11-17 10:00');
    $this->author = User::factory()->create(['first_name' => 'Ama', 'last_name' => 'Mensah']);
    attend($this->author, '2026-11-17');
});

function arAs(object $test, User $user): void
{
    app('auth')->forgetGuards();
    $test->flushHeaders();
    Sanctum::actingAs($user);
}

function arSend(object $test, User $user, array $overrides = [])
{
    arAs($test, $user);

    return $test->post('/api/me/reports/activity', $overrides + ['title' => 'Visit to the Tema office', 'file' => pdfUpload()], ['Accept' => 'application/json']);
}

function arNotified(User $user): bool
{
    return Notification::sent($user, StaffAlert::class, fn (StaffAlert $n) => $n->kind === 'activity_report')->isNotEmpty();
}

describe('sending', function () {
    it('requires authentication', function (string $method, string $uri) {
        $this->json($method, $uri)->assertUnauthorized();
    })->with([
        ['GET', '/api/me/reports/activity'], ['POST', '/api/me/reports/activity'], ['DELETE', '/api/me/reports/activity/1'],
        ['GET', '/api/admin/reports/activity'], ['GET', '/api/admin/reports/activity/1'],
    ]);

    it('stores the PDF privately on any day and tells the readers at once, in the application only', function () {
        $super = User::factory()->superAdmin()->create();
        $viewer = User::factory()->admin(Permission::ViewReports)->create();
        $receiver = User::factory()->admin(Permission::ReceiveWeeklyReports)->create();
        $noTier = User::factory()->admin(Permission::ViewLateness, Permission::ViewClients)->create();
        $colleague = User::factory()->create();

        $r = arSend($this, $this->author, ['title' => "  Visit to   the Tema office \n", 'note' => 'Met the manager.'])->assertCreated();
        $report = ActivityReport::query()->sole();
        expect($report->title)->toBe('Visit to the Tema office')->and($report->note)->toBe('Met the manager.')
            ->and($report->work_date->toDateString())->toBe('2026-11-17')
            ->and($report->submitted_at->toIso8601String())->toBe(now()->toIso8601String())
            ->and($report->path)->toStartWith("activity-reports/{$this->author->id}/2026-11-17/")->toEndWith('.pdf')
            ->and(strlen($report->sha256))->toBe(64);
        Storage::disk('local')->assertExists($report->path);
        $r->assertJsonPath('data.id', $report->id)->assertJsonPath('data.title', 'Visit to the Tema office')->assertJsonPath('data.note', 'Met the manager.')
            ->assertJsonPath('data.work_date', '2026-11-17')->assertJsonPath('data.file_name', 'report.pdf')
            ->assertJsonPath('data.can_delete', true)->assertJsonPath('data.delete_until', now()->addMinutes(30)->toIso8601String())
            ->assertJsonMissingPath('data.path')->assertJsonMissingPath('data.sha256');
        expect($r->json('data.file_url'))->toContain('/api/files/activity-reports/')->toContain('signature=');
        $this->get($r->json('data.file_url'))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="report.pdf"');

        expect(ActivityLog::query()->where('event', 'reports.activity_submitted')->where('user_id', $this->author->id)->count())->toBe(1);
        foreach ([$super, $viewer, $receiver] as $reader) {
            Notification::assertSentTo($reader, StaffAlert::class, fn (StaffAlert $n, array $channels) => $n->kind === 'activity_report'
                && $channels === ['database'] && $n->title === 'Activity report from Ama Mensah'
                && $n->body === 'Ama Mensah sent an activity report: Visit to the Tema office'
                && $n->path === '/admin/reports/activity/'.$report->id && $n->data['activity_report_id'] === $report->id);
        }
        expect(arNotified($noTier))->toBeFalse()->and(arNotified($colleague))->toBeFalse()->and(arNotified($this->author))->toBeFalse();
    });

    it('also e-mails the readers when the setting is on', function () {
        app(SettingsService::class)->set(['activity_report_email' => true]);
        $super = User::factory()->superAdmin()->create();
        arSend($this, $this->author)->assertCreated();
        Notification::assertSentTo($super, StaffAlert::class, fn (StaffAlert $n, array $channels) => $n->kind === 'activity_report' && $channels === ['database', 'mail']);
    });

    it('needs today\'s check-in on an expected day, like tasks and clients', function () {
        $late = User::factory()->create();
        arSend($this, $late)->assertForbidden()->assertJsonPath('code', 'checkin_required');
        $this->getJson('/api/me/reports/activity')->assertOk()->assertJsonPath('meta.total', 0);
        attend($late, '2026-11-17');
        arSend($this, $late)->assertCreated();
        // Saturday: nobody is expected, no check-in needed.
        at('2026-11-21 20:00');
        arSend($this, $late)->assertCreated()->assertJsonPath('data.work_date', '2026-11-21');
        expect(ActivityReport::query()->count())->toBe(2);
    });

    it('is refused to accounts that do not track attendance', function () {
        arSend($this, User::factory()->superAdmin()->create())->assertForbidden()->assertJsonPath('code', 'employees_only');
        $admin = User::factory()->admin(...Permission::cases())->create();
        arSend($this, $admin)->assertForbidden()->assertJsonPath('code', 'employees_only');
        $this->getJson('/api/me/reports/activity')->assertForbidden();
        expect(ActivityReport::query()->count())->toBe(0);
    });

    it('validates the title, the note and the PDF like the weekly report', function (array $overrides, ?string $code, ?string $field) {
        app(SettingsService::class)->set(['report_pdf_max_mb' => 1]);
        $r = arSend($this, $this->author, $overrides)->assertUnprocessable();
        if ($code !== null) {
            $r->assertJsonPath('code', $code);
        }
        if ($field !== null) {
            $r->assertJsonValidationErrors([$field]);
        }
        expect(ActivityReport::query()->count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
    })->with([
        'no title' => [['title' => ''], null, 'title'],
        'blank title' => [['title' => "  \n "], null, 'title'],
        'title too long' => [['title' => str_repeat('a', 151)], null, 'title'],
        'link in title' => [['title' => 'See www.evil.example now'], null, 'title'],
        'url in title' => [['title' => 'Read https://evil'], null, 'title'],
        'e-mail in title' => [['title' => 'Write to boss@example'], null, 'title'],
        'markup in title' => [['title' => '<b>Bold</b>'], null, 'title'],
        'note too long' => [['note' => str_repeat('n', 1001)], null, 'note'],
        'no file' => [['file' => null], null, 'file'],
        'not a pdf' => [['file' => UploadedFile::fake()->createWithContent('report.pdf', '<html><script>alert(1)</script></html>')], 'invalid_pdf', null],
        'windows program' => [['file' => UploadedFile::fake()->createWithContent('report.pdf', "MZ\x90\x00binary")], 'invalid_pdf', null],
        'active content' => [['file' => UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /OpenAction << /S /JavaScript /JS (app.alert\\(1\\)) >> >>\nendobj\n%%EOF\n")], 'pdf_active_content', null],
        'too large' => [['file' => pdfUpload('big.pdf', 1100)], null, 'file'],
        'file sent as text' => [['file' => 'report.pdf'], null, 'file'],
    ]);

    it('accepts a title of 150 characters and a note of 1000', function () {
        arSend($this, $this->author, ['title' => str_repeat('t', 150), 'note' => str_repeat('n', 1000)])->assertCreated();
    });

    it('limits the number of reports per day, deleted ones included', function () {
        app(SettingsService::class)->set(['activity_reports_per_day' => 2]);
        $first = arSend($this, $this->author)->assertCreated()->json('data.id');
        arSend($this, $this->author)->assertCreated();
        arSend($this, $this->author)->assertUnprocessable()->assertJsonPath('code', 'too_many_activity_reports')->assertJsonPath('limit', 2);
        $this->getJson('/api/me/reports/activity')->assertOk()->assertJsonPath('limits.per_day', 2)->assertJsonPath('limits.sent_today', 2)
            ->assertJsonPath('limits.remaining_today', 0)->assertJsonPath('limits.delete_minutes', 30)->assertJsonPath('limits.max_size_mb', 10);

        $this->deleteJson("/api/me/reports/activity/{$first}")->assertNoContent();
        arSend($this, $this->author)->assertUnprocessable()->assertJsonPath('code', 'too_many_activity_reports');
        expect(count(Storage::disk('local')->allFiles()))->toBe(1);

        // Another person has their own limit; the next day starts again.
        $other = User::factory()->create();
        attend($other, '2026-11-17');
        arSend($this, $other)->assertCreated();
        at('2026-11-18 09:00');
        attend($this->author, '2026-11-18');
        arSend($this, $this->author)->assertCreated();
    });

    it('never counts as the weekly report', function () {
        $super = User::factory()->superAdmin()->create();
        at('2026-11-20 16:30');
        attend($this->author, '2026-11-20');
        arSend($this, $this->author)->assertCreated();
        $this->getJson('/api/me/reports/weekly/submission')->assertOk()->assertJsonPath('data.current', null)->assertJsonCount(0, 'data.versions');
        arAs($this, $super);
        $this->getJson('/api/admin/reports/weekly')->assertOk()->assertJsonPath('received_count', 0)->assertJsonPath('expected_count', 1);
        $this->getJson('/api/admin/dashboard')->assertJsonPath('data.week.weekly_reports_received', 0);
    });
});

describe('the author', function () {
    it('lists only their own reports, newest first, paginated', function () {
        $other = User::factory()->create();
        attend($other, '2026-11-17');
        arSend($this, $other, ['title' => 'Not mine'])->assertCreated();
        arSend($this, $this->author, ['title' => 'First'])->assertCreated();
        at('2026-11-17 11:00');
        arSend($this, $this->author, ['title' => 'Second'])->assertCreated();
        at('2026-11-17 12:00');
        arSend($this, $this->author, ['title' => 'Third'])->assertCreated();

        $r = $this->getJson('/api/me/reports/activity?per_page=2')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        expect(collect($r->json('data'))->pluck('title')->all())->toBe(['Third', 'Second'])
            ->and($r->json('data.0'))->toHaveKeys(['id', 'title', 'note', 'work_date', 'file_name', 'size_bytes', 'submitted_at', 'file_url', 'can_delete', 'delete_until'])
            ->and($r->json('data.0'))->not->toHaveKey('user');
        expect(collect($this->getJson('/api/me/reports/activity?per_page=2&page=2')->json('data'))->pluck('title')->all())->toBe(['First']);
        $this->getJson('/api/me/reports/activity?per_page=500')->assertUnprocessable();
    });

    it('deletes a report within the allowed delay only; the file is removed and the action logged', function () {
        $id = arSend($this, $this->author)->assertCreated()->json('data.id');
        $path = ActivityReport::query()->value('path');
        $kept = arSend($this, $this->author, ['title' => 'Kept'])->assertCreated()->json('data.id');

        at('2026-11-17 10:30');
        $this->deleteJson("/api/me/reports/activity/{$id}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
        expect(ActivityReport::query()->pluck('id')->all())->toBe([$kept])
            ->and(ActivityReport::withTrashed()->count())->toBe(2)
            ->and(ActivityLog::query()->where('event', 'reports.activity_deleted')->where('user_id', $this->author->id)->count())->toBe(1);
        $this->deleteJson("/api/me/reports/activity/{$id}")->assertNotFound();
        $this->getJson('/api/me/reports/activity')->assertJsonPath('meta.total', 1);

        at('2026-11-17 10:30:01');
        $this->getJson('/api/me/reports/activity')->assertJsonPath('data.0.can_delete', false);
        $this->deleteJson("/api/me/reports/activity/{$kept}")->assertUnprocessable()->assertJsonPath('code', 'delete_window_closed');
        expect(ActivityReport::query()->count())->toBe(1);
    });

    it('cannot delete at all when the delay is 0, nor someone else\'s report, nor without today\'s check-in', function () {
        $id = arSend($this, $this->author)->assertCreated()->json('data.id');
        $other = User::factory()->create();
        arAs($this, $other);
        $this->deleteJson("/api/me/reports/activity/{$id}")->assertForbidden()->assertJsonPath('code', 'checkin_required');
        attend($other, '2026-11-17');
        $this->deleteJson("/api/me/reports/activity/{$id}")->assertNotFound();
        $this->getJson("/api/admin/reports/activity/{$id}")->assertForbidden();
        $this->getJson('/api/admin/reports/activity')->assertForbidden();

        app(SettingsService::class)->set(['activity_report_delete_minutes' => 0]);
        arAs($this, $this->author);
        $this->getJson('/api/me/reports/activity')->assertJsonPath('data.0.can_delete', false)->assertJsonPath('data.0.delete_until', null);
        $this->deleteJson("/api/me/reports/activity/{$id}")->assertUnprocessable()->assertJsonPath('code', 'delete_window_closed');
        expect(ActivityReport::query()->count())->toBe(1);
    });
});

describe('the readers', function () {
    beforeEach(function () {
        $this->super = User::factory()->superAdmin()->create();
        $this->viewer = User::factory()->admin(Permission::ViewReports)->create();
        $this->other = User::factory()->create(['first_name' => 'Kofi', 'last_name' => 'Boateng']);
        attend($this->other, '2026-11-17');
        $this->first = arSend($this, $this->author, ['title' => 'Tuesday visit'])->assertCreated()->json('data.id');
        at('2026-11-19 15:00');
        attend($this->other, '2026-11-19');
        $this->second = arSend($this, $this->other, ['title' => 'Thursday training'])->assertCreated()->json('data.id');
    });

    it('list the reports with filters and get a download link', function (string $who) {
        arAs($this, $who === 'super' ? $this->super : ($who === 'viewer' ? $this->viewer : User::factory()->admin(Permission::ReceiveWeeklyReports)->create()));
        $r = $this->getJson('/api/admin/reports/activity')->assertOk()->assertJsonPath('meta.total', 2);
        expect(collect($r->json('data'))->pluck('title')->all())->toBe(['Thursday training', 'Tuesday visit'])
            ->and($r->json('data.0.user.id'))->toBe($this->other->id)->and($r->json('data.0.user.role'))->toBe('employee')
            ->and($r->json('data.0'))->not->toHaveKeys(['can_delete', 'delete_until', 'path']);
        $this->getJson("/api/admin/reports/activity?user_id={$this->author->id}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->first);
        $this->getJson('/api/admin/reports/activity?from=2026-11-18')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->second);
        $this->getJson('/api/admin/reports/activity?from=2026-11-17&to=2026-11-17')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->first);
        $this->getJson('/api/admin/reports/activity?from=2026-11-20&to=2026-11-18')->assertUnprocessable();
        $this->getJson('/api/admin/reports/activity?per_page=1')->assertJsonPath('meta.last_page', 2);

        $url = $this->getJson("/api/admin/reports/activity/{$this->first}")->assertOk()->assertJsonPath('data.title', 'Tuesday visit')
            ->assertJsonPath('data.user.id', $this->author->id)->json('data.file_url');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->getJson('/api/admin/reports/activity/999999')->assertNotFound();
    })->with(['super', 'viewer', 'receiver']);

    it('are refused without a report tier, whatever the other permissions', function () {
        $others = array_filter(Permission::cases(), fn (Permission $p) => ! in_array($p, [Permission::ViewReports, Permission::ReceiveWeeklyReports], true));
        arAs($this, User::factory()->admin(...$others)->create());
        $this->getJson('/api/admin/reports/activity')->assertForbidden();
        $this->getJson("/api/admin/reports/activity/{$this->first}")->assertForbidden();
        $this->getJson('/api/admin/reports/activity/999999')->assertForbidden();
        arAs($this, $this->author);
        $this->getJson('/api/admin/reports/activity')->assertForbidden();
        $this->getJson("/api/admin/reports/activity/{$this->second}")->assertForbidden();
    });

    it('no longer see a report deleted by its author', function () {
        arAs($this, $this->other);
        $this->deleteJson("/api/me/reports/activity/{$this->second}")->assertNoContent();
        arAs($this, $this->super);
        $this->getJson('/api/admin/reports/activity')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/admin/reports/activity/{$this->second}")->assertNotFound();
    });

    it('read an admin\'s activity report only when they are super admins', function () {
        adminsTrackFrom('2026-11-19');
        $author = User::factory()->admin(Permission::ViewReports, Permission::ReceiveWeeklyReports)->create(['first_name' => 'Tara', 'last_name' => 'Admin']);
        attend($author, '2026-11-19');
        Notification::fake();
        $id = arSend($this, $author, ['title' => 'Board meeting'])->assertCreated()->json('data.id');

        // Told: super admins only – not the admins holding the report tiers, not the author.
        expect(arNotified($this->super))->toBeTrue()->and(arNotified($this->viewer))->toBeFalse()->and(arNotified($author))->toBeFalse();
        // The author sees it among their own reports, never in the admin pages.
        $this->getJson('/api/me/reports/activity')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/reports/activity')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/admin/reports/activity/{$id}")->assertNotFound();
        $this->getJson("/api/admin/reports/activity?user_id={$author->id}")->assertJsonPath('meta.total', 0);

        arAs($this, $this->viewer);
        $this->getJson('/api/admin/reports/activity')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson("/api/admin/reports/activity/{$id}")->assertNotFound();
        $this->getJson("/api/admin/reports/activity?user_id={$author->id}")->assertJsonPath('meta.total', 0);

        arAs($this, $this->super);
        $this->getJson('/api/admin/reports/activity')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.user.role', 'admin');
        $this->getJson("/api/admin/reports/activity/{$id}")->assertOk()->assertJsonPath('data.title', 'Board meeting');
    });
});

it('has its three settings in the reports section', function () {
    $all = SettingDefinitions::all();
    expect($all['activity_reports_per_day'])->toMatchArray(['default' => 5, 'section' => 'reports', 'super_admin_only' => false])
        ->and($all['activity_report_delete_minutes'])->toMatchArray(['default' => 30, 'section' => 'reports'])
        ->and($all['activity_report_email'])->toMatchArray(['default' => false, 'type' => 'bool', 'section' => 'reports']);
    Sanctum::actingAs(User::factory()->admin(Permission::ManageSettingsReports)->create());
    $this->putJson('/api/admin/settings', ['values' => ['activity_reports_per_day' => 3, 'activity_report_delete_minutes' => 0, 'activity_report_email' => true]])->assertOk();
    $this->putJson('/api/admin/settings', ['values' => ['activity_reports_per_day' => 0]])->assertUnprocessable();
});
