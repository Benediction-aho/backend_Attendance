<?php

use App\Enums\Permission;
use App\Mail\WeeklyReportsMail;
use App\Models\Client;
use App\Models\Leave;
use App\Models\ScheduledRun;
use App\Models\User;
use App\Models\WeeklyReportSubmission;
use App\Settings\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

// 2026-11-20 is a Friday (window 16:00 → 17:00, reminder 16:30).
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    at('2026-11-09 08:00');
});

function submit(object $test, User $user, ?UploadedFile $file = null)
{
    Sanctum::actingAs($user);

    return $test->postJson('/api/me/reports/weekly/submission', ['file' => $file ?? pdfUpload()]);
}

function notificationsOf(User $user, string $kind): int
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->where('data->kind', $kind)->count();
}

describe('upload window', function () {
    it('refuses uploads before Friday 16:00 once last week was sent', function () {
        $u = User::factory()->create();
        WeeklyReportSubmission::query()->create(['user_id' => $u->id, 'week_start' => '2026-11-09', 'window_date' => '2026-11-13', 'version' => 1,
            'path' => 'x.pdf', 'original_name' => 'x.pdf', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'submitted_at' => now(), 'is_late' => false]);
        at('2026-11-20 15:59:59');
        attend($u, '2026-11-20');
        submit($this, $u)->assertUnprocessable()->assertJsonPath('code', 'window_not_open');
        $this->getJson('/api/me/reports/weekly/submission')->assertOk()->assertJsonPath('data.can_submit', false);
    });

    it('accepts on time from 16:00 to 17:00:00 and flags later uploads as late', function () {
        [$a, $b, $c] = User::factory()->count(3)->create();
        foreach ([$a, $b, $c] as $u) {
            attend($u, '2026-11-20');
        }
        at('2026-11-20 16:00');
        submit($this, $a)->assertCreated()->assertJsonPath('data.is_late', false)->assertJsonPath('data.week_start', '2026-11-16')->assertJsonPath('data.version', 1);
        at('2026-11-20 17:00:00');
        submit($this, $b)->assertCreated()->assertJsonPath('data.is_late', false);
        at('2026-11-20 17:00:01');
        submit($this, $c)->assertCreated()->assertJsonPath('data.is_late', true);
        Storage::disk('local')->assertExists(WeeklyReportSubmission::query()->where('user_id', $a->id)->value('path'));
    });

    it('lets an employee absent on Friday submit on the next working day without being late', function () {
        $onLeave = User::factory()->create();
        Leave::query()->create(['user_id' => $onLeave->id, 'start_date' => '2026-11-20', 'end_date' => '2026-11-20']);
        $noCheckin = User::factory()->create();
        $present = User::factory()->create();
        attend($present, '2026-11-20');

        at('2026-11-23 10:00'); // Monday
        foreach ([$onLeave, $noCheckin, $present] as $u) {
            attend($u, '2026-11-23');
        }
        submit($this, $onLeave)->assertCreated()->assertJsonPath('data.is_late', false)->assertJsonPath('data.week_start', '2026-11-16');
        submit($this, $noCheckin)->assertCreated()->assertJsonPath('data.is_late', false);
        submit($this, $present)->assertCreated()->assertJsonPath('data.is_late', true);

        at('2026-11-24 10:00');
        $later = User::factory()->create(['created_at' => '2026-11-01']);
        Leave::query()->create(['user_id' => $later->id, 'start_date' => '2026-11-20', 'end_date' => '2026-11-20']);
        attend($later, '2026-11-24');
        submit($this, $later)->assertCreated()->assertJsonPath('data.is_late', true);
    });

    it('applies the grace without check-in only when the setting is on', function () {
        app(SettingsService::class)->set(['weekly_absent_friday_grace' => false]);
        $u = User::factory()->create();
        at('2026-11-23 10:00');
        attend($u, '2026-11-23');
        submit($this, $u)->assertCreated()->assertJsonPath('data.is_late', true);
    });

    it('keeps every version when the report is replaced, up to the configured maximum', function () {
        app(SettingsService::class)->set(['weekly_report_max_versions' => 2]);
        $u = User::factory()->create();
        at('2026-11-20 16:10');
        attend($u, '2026-11-20');
        $first = submit($this, $u)->assertCreated()->json('data.id');
        submit($this, $u, pdfUpload('v2.pdf'))->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.is_current', true);
        submit($this, $u)->assertUnprocessable()->assertJsonPath('code', 'too_many_versions');
        expect(WeeklyReportSubmission::query()->find($first)->is_current)->toBeFalse()
            ->and(WeeklyReportSubmission::query()->where('user_id', $u->id)->count())->toBe(2);
        Storage::disk('local')->assertExists(WeeklyReportSubmission::query()->find($first)->path);
        $r = $this->getJson('/api/me/reports/weekly/submission')->assertOk()->assertJsonPath('data.current.version', 2)->assertJsonCount(2, 'data.versions');
        expect($r->json('data.current.file_url'))->toContain('signature=');
        expect(count(Storage::disk('local')->allFiles('weekly-reports')))->toBe(2); // refused upload left no file
    });
});

describe('upload abuse', function () {
    beforeEach(function () {
        $this->u = User::factory()->create();
        at('2026-11-20 16:10');
        attend($this->u, '2026-11-20');
    });

    it('refuses files that are not PDFs whatever their name or declared type', function () {
        submit($this, $this->u, UploadedFile::fake()->createWithContent('report.pdf', '<html><script>alert(1)</script>'))->assertUnprocessable()->assertJsonPath('code', 'invalid_pdf');
        submit($this, $this->u, UploadedFile::fake()->createWithContent('x.pdf', "MZ\x90\x00 %PDF-1.4"))->assertUnprocessable();
        submit($this, $this->u, UploadedFile::fake()->create('empty.pdf', 0, 'application/pdf'))->assertUnprocessable();
        Sanctum::actingAs($this->u);
        $this->postJson('/api/me/reports/weekly/submission', ['file' => 'not a file'])->assertUnprocessable();
        expect(WeeklyReportSubmission::query()->count())->toBe(0);
    });

    it('refuses files above the size limit (setting) and stores server-generated names only', function () {
        app(SettingsService::class)->set(['report_pdf_max_mb' => 1]);
        submit($this, $this->u, pdfUpload('big.pdf', 1100))->assertUnprocessable();
        $r = submit($this, $this->u, pdfUpload('../../etc/<passwd>.pdf'))->assertCreated();
        $s = WeeklyReportSubmission::query()->first();
        expect($s->path)->toMatch('#^weekly-reports/\d+/2026-11-16/[0-9a-f-]{36}\.pdf$#')
            ->and($s->original_name)->not->toContain('/')->not->toContain('<');
        expect($r->json('data'))->not->toHaveKey('path')->not->toHaveKey('sha256');
    });
});

describe('Friday reminder (16:30)', function () {
    it('reminds expected employees without a report, once, and never on leave', function () {
        $sent = User::factory()->create();
        $missing = User::factory()->create();
        $onLeave = User::factory()->create();
        Leave::query()->create(['user_id' => $onLeave->id, 'start_date' => '2026-11-20', 'end_date' => '2026-11-20']);
        $admin = User::factory()->superAdmin()->create();
        at('2026-11-20 16:05');
        attend($sent, '2026-11-20');
        submit($this, $sent)->assertCreated();

        at('2026-11-20 16:29:59');
        $this->artisan('reports:weekly-reminder')->assertSuccessful();
        expect(notificationsOf($missing, 'weekly_report_reminder'))->toBe(0);

        at('2026-11-20 16:30');
        $this->artisan('reports:weekly-reminder')->assertSuccessful();
        $this->artisan('reports:weekly-reminder')->assertSuccessful();
        expect(notificationsOf($missing, 'weekly_report_reminder'))->toBe(1)
            ->and(notificationsOf($sent, 'weekly_report_reminder'))->toBe(0)
            ->and(notificationsOf($onLeave, 'weekly_report_reminder'))->toBe(0)
            ->and(notificationsOf($admin, 'weekly_report_reminder'))->toBe(0);
    });

    it('is caught up until 17:00 but not after', function () {
        $u = User::factory()->create();
        at('2026-11-20 17:00');
        $this->artisan('reports:weekly-reminder')->assertSuccessful();
        expect(notificationsOf($u, 'weekly_report_reminder'))->toBe(0);
        at('2026-11-20 16:59');
        $this->artisan('reports:weekly-reminder')->assertSuccessful();
        expect(notificationsOf($u, 'weekly_report_reminder'))->toBe(1);
    });
});

describe('Friday close (17:00) and late forwarding', function () {
    beforeEach(function () {
        // The previous Friday's close already ran.
        ScheduledRun::query()->create(['task' => 'weekly_close', 'run_key' => 'weekly_close:2026-11-13', 'status' => 'succeeded', 'started_at' => now()]);
    });

    it('notifies and emails super admins and receive_weekly_reports admins with the PDFs, once', function () {
        $super = User::factory()->superAdmin()->create();
        $receiver = User::factory()->admin(Permission::ReceiveWeeklyReports)->create();
        $viewer = User::factory()->admin(Permission::ViewReports)->create();
        $a = User::factory()->create(['first_name' => 'Ludmilla', 'last_name' => 'Ngari']);
        $b = User::factory()->create(['first_name' => 'Fosty', 'last_name' => 'Fofana']);
        at('2026-11-18 10:00');
        Client::query()->create(['user_id' => $a->id, 'work_date' => '2026-11-18', 'name' => 'Kofi', 'service' => 'Residence Permit', 'service_key' => 'residence permit', 'quantity' => 2]);
        at('2026-11-20 16:20');
        attend($a, '2026-11-20');
        submit($this, $a)->assertCreated();

        at('2026-11-20 16:59:59');
        $this->artisan('reports:weekly-close')->assertSuccessful();
        Mail::assertNothingQueued();

        at('2026-11-20 17:00');
        $this->artisan('reports:weekly-close')->assertSuccessful();
        $this->artisan('reports:weekly-close')->assertSuccessful();
        Mail::assertQueuedCount(2);
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($super->email) && $m->attach && count($m->attachments()) === 1
            && $m->title === 'Weekly reports: 1 of 2 received'
            && collect($m->lines)->contains(fn ($l) => str_contains($l, 'Kofi – Residence Permit × 2')));
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($receiver->email));
        Mail::assertNotQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($viewer->email));
        expect(notificationsOf($super, 'weekly_close'))->toBe(1)->and(notificationsOf($receiver, 'weekly_close'))->toBe(1)
            ->and(notificationsOf($viewer, 'weekly_close'))->toBe(0)
            ->and(DB::table('report_deliveries')->where('kind', 'weekly_close')->count())->toBe(2)
            ->and(ScheduledRun::query()->where('run_key', 'weekly_close:2026-11-20')->value('status'))->toBe('succeeded');
        expect(DB::table('notifications')->where('notifiable_id', $super->id)->value('data'))->toContain('Not received: Fosty Fofana');

        // Late upload: forwarded directly (in-app + email); a later replacement is not re-sent (P5-07).
        at('2026-11-20 18:00');
        attend($b, '2026-11-20');
        submit($this, $b)->assertCreated()->assertJsonPath('data.is_late', true);
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($receiver->email) && str_starts_with($m->title, 'Late weekly report from Fosty Fofana'));
        expect(notificationsOf($receiver, 'weekly_forward'))->toBe(1)->and(WeeklyReportSubmission::query()->where('user_id', $b->id)->value('forwarded_at'))->not->toBeNull();
        submit($this, $b)->assertCreated();
        expect(notificationsOf($receiver, 'weekly_forward'))->toBe(1);
    });

    it('sends secure in-app links instead of attachments above the size limit, even when nobody sent a report', function () {
        app(SettingsService::class)->set(['email_attachments_max_mb' => 1]);
        $super = User::factory()->superAdmin()->create();
        [$a, $b] = User::factory()->count(2)->create();
        at('2026-11-20 16:20');
        foreach ([$a, $b] as $u) {
            attend($u, '2026-11-20');
            submit($this, $u, pdfUpload('r.pdf', 600))->assertCreated();
        }
        at('2026-11-20 17:00');
        $this->artisan('reports:weekly-close')->assertSuccessful();
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => ! $m->attach && $m->attachments() === []
            && collect($m->lines)->contains(fn ($l) => str_contains($l, 'too large')));

        // Next week, nobody sends anything: the staff is still told.
        at('2026-11-27 17:05');
        $this->artisan('reports:weekly-close')->assertSuccessful();
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->title === 'Weekly reports: 0 of 2 received');
    });

    it('is caught up after a missed Friday run but not beyond report_catchup_days', function () {
        User::factory()->superAdmin()->create();
        ScheduledRun::query()->where('run_key', 'weekly_close:2026-11-13')->delete();
        at('2026-11-23 09:00');
        $this->artisan('reports:weekly-close')->assertSuccessful();
        expect(ScheduledRun::query()->where('run_key', 'weekly_close:2026-11-20')->exists())->toBeTrue()
            ->and(ScheduledRun::query()->where('run_key', 'weekly_close:2026-11-13')->exists())->toBeFalse(); // older than 7 days
    });

    it('retries only the recipients not served when one delivery fails', function () {
        $s1 = User::factory()->superAdmin()->create();
        $s2 = User::factory()->superAdmin()->create();
        DB::table('report_deliveries')->insert(['kind' => 'weekly_close', 'ref' => '2026-11-20', 'user_id' => $s1->id, 'sent_at' => now()]);
        at('2026-11-20 17:00');
        $this->artisan('reports:weekly-close')->assertSuccessful();
        Mail::assertQueuedCount(1);
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($s2->email));
    });
});

describe('admin weekly views', function () {
    it('lists expected employees and PDFs for view_reports or receive_weekly_reports holders only', function () {
        $u = User::factory()->create();
        $other = User::factory()->create();
        at('2026-11-20 16:10');
        attend($u, '2026-11-20');
        $id = submit($this, $u)->json('data.id');

        foreach ([Permission::ViewReports, Permission::ReceiveWeeklyReports] as $p) {
            Sanctum::actingAs(User::factory()->admin($p)->create());
            $r = $this->getJson('/api/admin/reports/weekly?date=2026-11-18')->assertOk()
                ->assertJsonPath('expected_count', 2)->assertJsonPath('received_count', 1);
            expect(collect($r->json('data'))->firstWhere('user.id', $u->id)['submission']['id'])->toBe($id);
            $file = $this->getJson("/api/admin/reports/weekly/submissions/$id")->assertOk()
                ->assertJsonPath('data.user.id', $u->id)->assertJsonCount(1, 'data.versions')->json('data.file_url');
            $this->get($file)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        Sanctum::actingAs(User::factory()->admin(Permission::ViewClients, Permission::ViewTasks)->create());
        $this->getJson('/api/admin/reports/weekly')->assertForbidden();
        $this->getJson("/api/admin/reports/weekly/submissions/$id")->assertForbidden();
        Sanctum::actingAs($other);
        $this->getJson("/api/admin/reports/weekly/submissions/$id")->assertForbidden();
        $this->getJson('/api/admin/reports/weekly/submissions/999999')->assertForbidden();
        Sanctum::actingAs(User::factory()->superAdmin()->create());
        $this->getJson('/api/admin/reports/weekly/submissions/999999')->assertNotFound();
    });

    it('expires the temporary file links and rejects tampered ones', function () {
        $u = User::factory()->create();
        at('2026-11-20 16:10');
        attend($u, '2026-11-20');
        $url = submit($this, $u)->json('data.file_url');
        $this->get($url.'x')->assertNotFound();
        at('2026-11-20 16:21');
        $this->get($url)->assertNotFound();
    });
});
