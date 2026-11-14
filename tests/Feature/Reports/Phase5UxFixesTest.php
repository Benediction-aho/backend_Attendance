<?php

// Regression tests for docs/reports/phase-5-ux.md (UX5-02, UX5-03, UX5-05, UX5-06, UX5-08).

use App\Domain\Reports\ReportBuilder;
use App\Enums\Permission;
use App\Mail\WeeklyReportsMail;
use App\Models\ActivityLog;
use App\Models\DailyReport;
use App\Models\ScheduledRun;
use App\Models\User;
use App\Models\WeeklyReportSubmission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
});

describe('UX5-02 expected population of past runs', function () {
    it('weekly catch-up counts only employees who were members on that Friday', function () {
        $super = User::factory()->superAdmin()->create(['created_at' => Carbon::parse('2026-01-01')]);
        $old = User::factory()->create(['last_name' => 'Old', 'created_at' => Carbon::parse('2026-01-01')]);
        // Joined after the Friday of 6 Nov (created, or approved later than created).
        User::factory()->create(['last_name' => 'New', 'created_at' => Carbon::parse('2026-11-12 10:00')]);
        User::factory()->create(['last_name' => 'Late', 'created_at' => Carbon::parse('2026-11-01'), 'approved_at' => Carbon::parse('2026-11-09 08:00')]);
        at('2026-11-13 09:00'); // close of 6 Nov never ran

        $this->artisan('reports:weekly-close')->assertSuccessful();
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($super->email) && $m->title === 'Weekly reports: 0 of 1 received'
            && collect($m->lines)->contains(fn ($l) => str_contains($l, $old->first_name.' Old')));

        Sanctum::actingAs($super);
        $this->getJson('/api/admin/reports/weekly?date=2026-11-04')->assertOk()->assertJsonPath('expected_count', 1);
        $this->getJson('/api/admin/reports/weekly?date=2026-11-11')->assertOk()->assertJsonPath('expected_count', 3);
    });

    it('daily catch-up does not create reports for accounts approved after that day', function () {
        User::factory()->create(['created_at' => Carbon::parse('2026-11-01'), 'approved_at' => Carbon::parse('2026-11-12 10:00')]);
        $old = User::factory()->create(['created_at' => Carbon::parse('2026-01-01')]);
        at('2026-11-12 23:30');
        $this->artisan('reports:daily')->assertSuccessful();
        expect(DailyReport::query()->whereDate('work_date', '2026-11-11')->pluck('user_id')->all())->toBe([$old->id]);
    });

    it('monthly rows leave out accounts that joined after the period, unless they have data', function () {
        $old = User::factory()->create(['created_at' => Carbon::parse('2026-01-01')]);
        User::factory()->create(['created_at' => Carbon::parse('2026-11-02')]);
        at('2026-11-02 12:00');
        $rows = app(ReportBuilder::class)->monthlyRows('2026-10-01', '2026-10-31');
        expect(array_column($rows, 'user_id'))->toBe([$old->id]);
    });
});

describe('UX5-03 missing list', function () {
    it('lists missing employees one per line with job title, email only for allowed recipients, and in the API', function () {
        $super = User::factory()->superAdmin()->create(['created_at' => Carbon::parse('2026-01-01')]);
        $receiver = User::factory()->admin(Permission::ReceiveWeeklyReports)->create(['created_at' => Carbon::parse('2026-01-01')]);
        $a = User::factory()->create(['first_name' => 'Ama', 'last_name' => 'Mensah', 'job_title' => 'Accountant', 'created_at' => Carbon::parse('2026-01-01')]);
        $b = User::factory()->create(['first_name' => 'Ama', 'last_name' => 'Mensah', 'job_title' => 'Driver', 'created_at' => Carbon::parse('2026-01-01')]);
        at('2026-11-20 17:00');
        $this->artisan('reports:weekly-close')->assertSuccessful();

        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($super->email)
            && in_array('Not received (2):', $m->lines, true)
            && in_array('  • Ama Mensah – Accountant ('.$a->email.')', $m->lines, true)
            && in_array('  • Ama Mensah – Driver ('.$b->email.')', $m->lines, true));
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($receiver->email)
            && in_array('  • Ama Mensah – Accountant', $m->lines, true)
            && ! collect($m->lines)->contains(fn ($l) => str_contains($l, $a->email)));
        expect(collect(Mail::queued(WeeklyReportsMail::class))->first()->lines[0])->not->toContain('Mensah,');

        Sanctum::actingAs($receiver);
        $r = $this->getJson('/api/admin/reports/weekly?date=2026-11-18')->assertOk();
        expect(collect($r->json('missing'))->pluck('job_title')->sort()->values()->all())->toBe(['Accountant', 'Driver'])
            ->and($r->json('missing.0'))->not->toHaveKey('email');
        Sanctum::actingAs($super);
        expect($this->getJson('/api/admin/reports/weekly?date=2026-11-18')->json('missing.0'))->toHaveKey('email');

        WeeklyReportSubmission::query()->create(['user_id' => $a->id, 'week_start' => '2026-11-16', 'window_date' => '2026-11-20', 'version' => 1, 'is_current' => true,
            'path' => 'x.pdf', 'original_name' => 'x.pdf', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'submitted_at' => now(), 'is_late' => true]);
        expect(collect($this->getJson('/api/admin/reports/weekly?date=2026-11-18')->json('missing'))->pluck('id')->all())->toBe([$b->id]);
    });
});

describe('UX5-05 / UX5-06 validation messages', function () {
    beforeEach(function () {
        at('2026-11-16 09:00');
        $this->u = User::factory()->create(['created_at' => Carbon::parse('2026-01-01')]);
        attend($this->u, '2026-11-16');
        Sanctum::actingAs($this->u);
    });

    it('refuses quantity 0, negative, decimal and above max with a plain message, never coercing', function () {
        foreach ([0, -1, 1.5, 1000, 'abc'] as $q) {
            $this->postJson('/api/me/clients', ['name' => 'n', 'service' => 's', 'quantity' => $q])->assertStatus(422)
                ->assertJsonFragment(['Quantity must be between 1 and 999.']);
        }
        $id = $this->postJson('/api/me/clients', ['name' => 'n', 'service' => 's', 'quantity' => 1])->assertCreated()->json('data.id');
        $this->patchJson("/api/me/clients/{$id}", ['quantity' => 0])->assertStatus(422)->assertJsonFragment(['Quantity must be between 1 and 999.']);
        $this->postJson('/api/me/clients', ['name' => str_repeat('a', 201), 'service' => 's', 'quantity' => 1])->assertStatus(422)
            ->assertJsonFragment(['The client name must be 200 characters or fewer.']);
    });

    it('uses plain messages for tasks', function () {
        $this->postJson('/api/me/tasks', ['title' => str_repeat('a', 501)])->assertStatus(422)->assertJsonFragment(['The task must be 500 characters or fewer.']);
        $this->postJson('/api/me/tasks', [])->assertStatus(422)->assertJsonFragment(['Write the task first.']);
    });
});

describe('UX5-08 monthly homonyms', function () {
    it('adds the job title, then the email local part, to same-name rows only', function () {
        at('2026-11-02 12:00');
        $c = Carbon::parse('2026-01-01');
        User::factory()->create(['first_name' => 'Efua', 'last_name' => 'Mensah', 'job_title' => 'Accountant', 'email' => 'efua.a@example.test', 'created_at' => $c]);
        User::factory()->create(['first_name' => 'Efua', 'last_name' => 'Mensah', 'job_title' => 'Driver', 'email' => 'efua.b@example.test', 'created_at' => $c]);
        User::factory()->create(['first_name' => 'Efua', 'last_name' => 'Mensah', 'job_title' => 'Driver', 'email' => 'efua.c@example.test', 'created_at' => $c]);
        User::factory()->create(['first_name' => 'Kojo', 'last_name' => 'Asante', 'created_at' => $c]);
        $rows = app(ReportBuilder::class)->monthlyRows('2026-10-01', '2026-10-31');
        expect(collect($rows)->pluck('detail')->all())->toBe([null, 'Accountant', 'Driver, efua.b', 'Driver, efua.c']);
        $html = view('pdf.monthly', ['rows' => $rows])->render();
        expect($html)->toContain('Driver, efua.c')->toContain('font-size: 10.5pt');
    });
});

describe('UX5-09 empty weekly close', function () {
    it('sends nothing when nobody was expected, records the run as done and logs it', function () {
        $super = User::factory()->superAdmin()->create(['created_at' => Carbon::parse('2026-01-01')]);
        User::factory()->create(['created_at' => Carbon::parse('2026-11-12 10:00')]);
        at('2026-11-13 09:00'); // catch-up of 6 Nov: nobody was a member then

        $this->artisan('reports:weekly-close')->assertSuccessful();
        Mail::assertNotQueued(WeeklyReportsMail::class, fn ($m) => str_contains($m->title, '0 of 0'));
        expect(ScheduledRun::query()->where('run_key', 'weekly_close:2026-11-06')->value('status'))->toBe('succeeded')
            ->and(ActivityLog::query()->where('event', 'reports.weekly_close_skipped')->count())->toBe(1)
            ->and(DB::table('notifications')->where('notifiable_id', $super->id)->count())->toBe(0);

        $this->artisan('reports:weekly-close')->assertSuccessful(); // never twice
        expect(ActivityLog::query()->where('event', 'reports.weekly_close_skipped')->count())->toBe(1);
    });
});
