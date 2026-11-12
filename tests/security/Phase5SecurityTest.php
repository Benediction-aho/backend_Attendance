<?php

// Phase 5 security checks (tasks, clients, reports). LOCAL ONLY, frozen clock, fake mail/storage.
// Run on a private database (never attendance_e2e):
//   DB_DATABASE=attendance_sec5 vendor/bin/pest tests/security/Phase5SecurityTest.php
// A FAIL here is a finding of docs/reports/phase-5-security.md (test name carries the ID).

use App\Domain\Reports\MonthlyReportService;
use App\Domain\Reports\ReportBuilder;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Mail\MonthlyReportMail;
use App\Mail\WeeklyReportsMail;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyReportSubmission;
use App\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

require_once __DIR__.'/../Support/attendance.php';

// 2026-11-16 Monday … 2026-11-20 Friday (window 16:00-17:00).
beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    at('2026-11-16 09:00');
});

function emp(): User
{
    return User::factory()->create(['created_at' => Carbon::parse('2026-01-01')]);
}

function pdfBytes(string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent('r.pdf', $body);
}

describe('check-in gate (P3-06)', function () {
    it('refuses every write before the check-in', function () {
        $u = emp();
        $other = emp();
        attend($other, '2026-11-16');
        $t = Task::query()->create(['user_id' => $u->id, 'work_date' => '2026-11-13', 'title' => 'old', 'status' => 'planned']);
        $c = Client::query()->create(['user_id' => $u->id, 'work_date' => '2026-11-13', 'name' => 'n', 'service' => 's', 'service_key' => 's', 'quantity' => 1]);
        Sanctum::actingAs($u);
        foreach ([
            ['postJson', '/api/me/tasks', ['title' => 'x']],
            ['patchJson', "/api/me/tasks/{$t->id}", ['done' => true]],
            ['deleteJson', "/api/me/tasks/{$t->id}", []],
            ['postJson', '/api/me/clients', ['name' => 'a', 'service' => 'b', 'quantity' => 1]],
            ['patchJson', "/api/me/clients/{$c->id}", ['quantity' => 2]],
            ['deleteJson', "/api/me/clients/{$c->id}", []],
            ['postJson', '/api/me/reports/weekly/submission', ['file' => pdfUpload()]],
        ] as [$m, $url, $body]) {
            $this->{$m}($url, $body)->assertStatus(403)->assertJsonPath('code', 'checkin_required');
        }
    });
});

describe('IDOR and mass assignment', function () {
    it('hides and protects other users rows', function () {
        $a = emp();
        $b = emp();
        attend($b, '2026-11-16');
        $t = Task::query()->create(['user_id' => $a->id, 'work_date' => '2026-11-16', 'title' => 'secret', 'status' => 'planned']);
        $c = Client::query()->create(['user_id' => $a->id, 'work_date' => '2026-11-16', 'name' => 'ACME', 'service' => 's', 'service_key' => 's', 'quantity' => 1]);
        Sanctum::actingAs($b);
        $this->patchJson("/api/me/tasks/{$t->id}", ['title' => 'pwn'])->assertStatus(404);
        $this->deleteJson("/api/me/tasks/{$t->id}")->assertStatus(404);
        $this->patchJson("/api/me/clients/{$c->id}", ['name' => 'pwn'])->assertStatus(404);
        $this->deleteJson("/api/me/clients/{$c->id}")->assertStatus(404);
        $this->patchJson('/api/me/tasks/999999', ['title' => 'x'])->assertStatus(404);
        expect($this->getJson('/api/me/tasks?user_id='.$a->id)->json('data'))->toBe([])
            ->and($this->getJson('/api/me/clients?user_id='.$a->id)->json('data'))->toBe([]);
        expect(Task::query()->find($t->id)->title)->toBe('secret')->and(Client::query()->find($c->id)->name)->toBe('ACME');
    });

    it('ignores user_id, work_date, status, completed_at, service_key in payloads', function () {
        $a = emp();
        $b = emp();
        attend($b, '2026-11-16');
        Sanctum::actingAs($b);
        $r = $this->postJson('/api/me/tasks', ['title' => 'x', 'done' => true, 'user_id' => $a->id, 'work_date' => '2020-01-01',
            'completed_at' => '2020-01-01T00:00:00Z', 'status' => 'done'])->assertStatus(201);
        $task = Task::query()->findOrFail($r->json('data.id'));
        expect($task->user_id)->toBe($b->id)->and($task->work_date->toDateString())->toBe('2026-11-16')
            ->and($task->completed_at->eq(now()))->toBeTrue();
        $r = $this->postJson('/api/me/clients', ['name' => 'n', 'service' => 'Visa', 'quantity' => 1, 'user_id' => $a->id,
            'work_date' => '2020-01-01', 'service_key' => 'zzz'])->assertStatus(201);
        $client = Client::query()->findOrFail($r->json('data.id'));
        expect($client->user_id)->toBe($b->id)->and($client->work_date->toDateString())->toBe('2026-11-16')->and($client->service_key)->toBe('visa');
    });
});

describe('input validation', function () {
    it('rejects out-of-range quantities', function () {
        $u = emp();
        attend($u, '2026-11-16');
        Sanctum::actingAs($u);
        foreach ([0, -1, 1000, 1.5, '1e3', 'abc', null, PHP_INT_MAX, [1]] as $q) {
            $this->postJson('/api/me/clients', ['name' => 'n', 'service' => 's', 'quantity' => $q])->assertStatus(422);
        }
    });

    it('never answers 500 to NUL bytes / odd unicode in free text', function () {
        $u = emp();
        attend($u, '2026-11-16');
        Sanctum::actingAs($u);
        foreach (["a\u{0000}b", "\u{202E}gnp.exe", str_repeat('é', 500), "\u{FEFF}", "\xC3\x28"] as $v) {
            expect($this->postJson('/api/me/tasks', ['title' => $v])->status())->toBeIn([201, 422]);
            expect($this->postJson('/api/me/clients', ['name' => $v, 'service' => $v, 'quantity' => 1])->status())->toBeIn([201, 422]);
        }
    });

    // P5-01: pdo_pgsql truncates at NUL: the API answers 201 with "a\u0000b" but stores "a".
    it('P5-01 stores exactly what it acknowledges (NUL bytes)', function () {
        $u = emp();
        attend($u, '2026-11-16');
        Sanctum::actingAs($u);
        $r = $this->postJson('/api/me/tasks', ['title' => "a\u{0000}b"]);
        if ($r->status() === 201) {
            expect(Task::query()->findOrFail($r->json('data.id'))->title)->toBe($r->json('data.title'));
        } else {
            expect($r->status())->toBe(422);
        }
    });

    it('enforces the daily caps', function () {
        $u = emp();
        attend($u, '2026-11-16');
        app(SettingsService::class)->set(['tasks_max_per_day' => 2, 'clients_max_per_day' => 2], null, Carbon::parse('2000-01-01'));
        Sanctum::actingAs($u);
        $this->postJson('/api/me/tasks', ['title' => 'a'])->assertStatus(201);
        $this->postJson('/api/me/tasks', ['title' => 'b'])->assertStatus(201);
        $this->postJson('/api/me/tasks', ['title' => 'c'])->assertStatus(422)->assertJsonPath('code', 'too_many_tasks');
        foreach ([201, 201, 422] as $s) {
            $this->postJson('/api/me/clients', ['name' => 'n', 'service' => 's', 'quantity' => 999])->assertStatus($s);
        }
    });
});

describe('service suggestions', function () {
    it('exposes only service labels, escapes LIKE wildcards', function () {
        $a = emp();
        Client::query()->create(['user_id' => $a->id, 'work_date' => '2026-11-10', 'name' => 'Secret Client', 'service' => 'Visa', 'service_key' => 'visa', 'quantity' => 1]);
        Client::query()->create(['user_id' => $a->id, 'work_date' => '2026-11-10', 'name' => 'X', 'service' => '100% loan', 'service_key' => Client::serviceKey('100% loan'), 'quantity' => 1]);
        $b = emp();
        Sanctum::actingAs($b);
        $all = $this->getJson('/api/clients/services')->assertOk()->json('data');
        expect(json_encode($all))->not->toContain('Secret Client')->not->toContain('user');
        expect(array_column($this->getJson('/api/clients/services?q=%25')->json('data'), 'service'))->toBe(['100% loan']);
        expect($this->getJson('/api/clients/services?q=_')->json('data'))->toBe([]);
        $this->getJson("/api/clients/services?q=' OR 1=1--")->assertOk();
        $admin = User::factory()->admin(Permission::ViewReports)->create();
        Sanctum::actingAs($admin);
        $this->getJson('/api/clients/services')->assertStatus(403);
    });
});

describe('admin report tiers', function () {
    beforeEach(function () {
        $this->e = emp();
        Task::query()->create(['user_id' => $this->e->id, 'work_date' => '2026-11-16', 'title' => 'TASK-TITLE', 'status' => 'planned']);
        Client::query()->create(['user_id' => $this->e->id, 'work_date' => '2026-11-16', 'name' => 'CLIENT-NAME', 'service' => 'Visa', 'service_key' => 'visa', 'quantity' => 1]);
        $this->sub = WeeklyReportSubmission::query()->create(['user_id' => $this->e->id, 'week_start' => '2026-11-16', 'window_date' => '2026-11-20', 'version' => 1,
            'path' => 'weekly-reports/x.pdf', 'original_name' => 'x.pdf', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'submitted_at' => now(), 'is_late' => false]);
    });

    it('refuses employees and tierless admins everywhere', function () {
        foreach ([$this->e, User::factory()->admin()->create(), User::factory()->admin(Permission::ViewClients, Permission::ViewTasks)->create()] as $who) {
            Sanctum::actingAs($who);
            $this->getJson('/api/admin/reports/daily')->assertStatus(403);
            $this->getJson('/api/admin/reports/weekly')->assertStatus(403);
            $this->getJson("/api/admin/reports/weekly/submissions/{$this->sub->id}")->assertStatus(403);
            $this->getJson('/api/admin/reports/weekly/submissions/999999')->assertStatus(403);
            $this->getJson("/api/admin/users/{$this->e->id}/reports/weekly")->assertStatus(403);
        }
    });

    it('filters task titles and client names on daily/user weekly', function () {
        Sanctum::actingAs(User::factory()->admin(Permission::ViewReports)->create());
        $body = $this->getJson('/api/admin/reports/daily?date=2026-11-16')->assertOk()->getContent();
        expect($body)->not->toContain('TASK-TITLE')->not->toContain('CLIENT-NAME');
        $body = $this->getJson("/api/admin/users/{$this->e->id}/reports/weekly?date=2026-11-16")->assertOk()->getContent();
        expect($body)->not->toContain('CLIENT-NAME');
    });

    // P5-02
    // P5-02 decision (SPECS 7): weekly-report recipients get the client list with the report.
    it('P5-02 shows client names to receive_weekly_reports holders (SPECS 7), consistently', function () {
        Sanctum::actingAs(User::factory()->admin(Permission::ReceiveWeeklyReports, Permission::ViewReports)->create());
        expect($this->getJson("/api/admin/reports/weekly/submissions/{$this->sub->id}")->assertOk()->getContent())->toContain('CLIENT-NAME');
        expect($this->getJson("/api/admin/users/{$this->e->id}/reports/weekly?date=2026-11-16")->assertOk()->getContent())->toContain('CLIENT-NAME');
    });

    it('P5-02b does not leak client names on the submission endpoint with view_reports only', function () {
        Sanctum::actingAs(User::factory()->admin(Permission::ViewReports)->create());
        $body = $this->getJson("/api/admin/reports/weekly/submissions/{$this->sub->id}")->assertOk()->getContent();
        expect($body)->not->toContain('CLIENT-NAME');
    });
});

describe('weekly upload abuse', function () {
    beforeEach(function () {
        $this->u = emp();
        attend($this->u, '2026-11-20');
        WeeklyReportSubmission::query()->create(['user_id' => $this->u->id, 'week_start' => '2026-11-09', 'window_date' => '2026-11-13', 'version' => 1,
            'path' => 'p.pdf', 'original_name' => 'p.pdf', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64), 'submitted_at' => now(), 'is_late' => false]);
    });

    it('refuses before the window, wrong magic, empty, oversize; sanitizes names; caps versions', function () {
        attend($this->u, '2026-11-19');
        at('2026-11-19 10:00');
        Sanctum::actingAs($this->u);
        $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertStatus(422)->assertJsonPath('code', 'window_not_open');
        at('2026-11-20 16:05');
        foreach (["MZ\x90\x00", '', '<html><script>alert(1)</script>%PDF-1.4', " %PDF-1.4\n"] as $bad) {
            $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfBytes($bad)])->assertStatus(422);
        }
        $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload('x.pdf', 10 * 1024 + 1)])->assertStatus(422);
        $r = $this->postJson('/api/me/reports/weekly/submission', ['file' => UploadedFile::fake()->createWithContent('../../../etc/<b>passwd</b>.php', "%PDF-1.4\n%%EOF")])->assertStatus(201);
        $s = WeeklyReportSubmission::query()->findOrFail($r->json('data.id'));
        expect($s->path)->toStartWith('weekly-reports/'.$this->u->id.'/2026-11-16/')->toEndWith('.pdf')
            ->and($s->original_name)->not->toContain('/')->not->toContain('<');
        for ($i = 2; $i <= 10; $i++) {
            $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertStatus(201);
        }
        $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertStatus(422)->assertJsonPath('code', 'too_many_versions');
        expect(count(Storage::disk('local')->allFiles('weekly-reports')))->toBe(10);
    });

    it('serves files only through valid, unexpired signed URLs, with safe headers', function () {
        at('2026-11-20 16:05');
        Sanctum::actingAs($this->u);
        $url = $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfBytes("%PDF-1.4\n<html><script>alert(document.domain)</script></html>")])->json('data.file_url');
        $res = $this->get($url)->assertOk();
        expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and((string) $res->headers->get('Content-Type'))->toContain('application/pdf');
        $p = parse_url($url);
        $this->get(str_replace('/files/weekly-reports/'.$this->u->id, '/files/weekly-reports/'.($this->u->id + 1), $url))->assertStatus(404);
        $this->get($p['path'])->assertStatus(404);
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url))->assertStatus(404);
        $this->get(preg_replace('/expires=\d+/', 'expires=9999999999', $url))->assertStatus(404);
        Carbon::setTestNow(now()->addMinutes(11));
        $this->get($url)->assertStatus(404);
    });

    // P5-03 (Info): a %PDF- polyglot is served inline from the API origin; the global CSP default-src 'none' blocks scripts.
    it('P5-03 serves uploaded files with a script-blocking CSP or as attachment', function () {
        at('2026-11-20 16:05');
        Sanctum::actingAs($this->u);
        $url = $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->json('data.file_url');
        $res = $this->get($url)->assertOk();
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $cd = (string) $res->headers->get('Content-Disposition');
        expect(str_contains($csp, "default-src 'none'") || str_contains($csp, 'sandbox') || str_starts_with($cd, 'attachment'))->toBeTrue();
    });

    it('marks lateness from server time, ignores client dates', function () {
        at('2026-11-20 17:00:01');
        Sanctum::actingAs($this->u);
        $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload(), 'submitted_at' => '2026-11-20T16:10:00', 'is_late' => false, 'week_start' => '2026-11-09'])
            ->assertStatus(201)->assertJsonPath('data.is_late', true)->assertJsonPath('data.week_start', '2026-11-16');
    });

    it('forwards only the first late version (P5-07)', function () {
        $admin = User::factory()->admin(Permission::ReceiveWeeklyReports)->create();
        at('2026-11-20 18:00');
        Sanctum::actingAs($this->u);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertStatus(201);
        }
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($admin->email));
        // P5-07: only the first version received after the close is forwarded.
        expect(Mail::queued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($admin->email))->count())->toBe(1);
    });

    // P5-04: a version replaced after the close removes the on-time one from the 17:00 count.
    it('P5-04 counts an on-time report even when replaced after the close', function () {
        User::factory()->superAdmin()->create();
        at('2026-11-20 16:10');
        Sanctum::actingAs($this->u);
        $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertStatus(201);
        at('2026-11-20 16:59:59');
        $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertStatus(201);
        // the close job runs a few seconds late, after a replacement at 17:00:30
        at('2026-11-20 17:00:30');
        $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertStatus(201);
        at('2026-11-20 17:01');
        Artisan::call('reports:weekly-close');
        $m = Mail::queued(WeeklyReportsMail::class)->first(fn ($m) => str_contains($m->title, 'received'));
        expect($m->title)->toContain('1 of 1');
    });
});

describe('grace', function () {
    it('Q23 grace: expected on Friday, no check-in, uploads Saturday → not late (by design, setting on)', function () {
        $u = emp();
        WeeklyReportSubmission::query()->create(['user_id' => $u->id, 'week_start' => '2026-11-09', 'window_date' => '2026-11-13', 'version' => 1,
            'path' => 'p.pdf', 'original_name' => 'p.pdf', 'size_bytes' => 1, 'sha256' => str_repeat('0', 64), 'submitted_at' => now(), 'is_late' => false]);
        at('2026-11-21 10:00');
        Sanctum::actingAs($u);
        $r = $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertStatus(201);
        expect($r->json('data.is_late'))->toBeFalse();
        app(SettingsService::class)->set(['weekly_absent_friday_grace' => false], null, Carbon::parse('2000-01-01'));
        $u2 = emp();
        Sanctum::actingAs($u2);
        $r = $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->assertStatus(201);
        expect($r->json('data.is_late'))->toBeTrue();
    });
});

describe('scheduled jobs', function () {
    it('weekly close: twice → once per recipient; missed → caught up; reminder not after close', function () {
        $sa = User::factory()->superAdmin()->create();
        $adm = User::factory()->admin(Permission::ReceiveWeeklyReports)->create();
        User::factory()->admin(Permission::ReceiveWeeklyReports)->status(UserStatus::Blocked)->create();
        $e = emp();
        at('2026-11-21 09:00'); // missed Friday 17:00
        Artisan::call('reports:weekly-close');
        Artisan::call('reports:weekly-close');
        expect(Mail::queued(WeeklyReportsMail::class)->count())->toBe(2);
        Artisan::call('reports:weekly-reminder');
        expect(DB::table('notifications')->where('notifiable_id', $e->id)->count())->toBe(0);
    });

    it('monthly: once, only super admins + listed active admins', function () {
        $sa = User::factory()->superAdmin()->create();
        $emp = emp();
        $adm = User::factory()->admin()->create();
        $blocked = User::factory()->admin()->status(UserStatus::Blocked)->create();
        app(SettingsService::class)->set(['monthly_report_recipient_ids' => [$emp->id, $adm->id, $blocked->id]], null, Carbon::parse('2000-01-01'));
        at('2026-11-27 08:00'); // missed the 26th 19:00
        Artisan::call('reports:monthly');
        Artisan::call('reports:monthly');
        $to = Mail::queued(MonthlyReportMail::class)->flatMap(fn ($m) => array_column($m->to, 'address'))->sort()->values()->all();
        expect($to)->toEqualCanonicalizing([$sa->email, $adm->email]);
    });

    it('monthly PDF escapes HTML and never loads remote/local resources', function () {
        $u = User::factory()->create(['first_name' => '<img src="file:///etc/passwd">', 'last_name' => '<link href="http://127.0.0.1:1/x.css">']);
        Client::query()->create(['user_id' => $u->id, 'work_date' => '2026-11-26', 'name' => 'n', 'service' => '<img src="http://127.0.0.1:1/ssrf.png">',
            'service_key' => 'x', 'quantity' => 2]);
        Client::query()->create(['user_id' => $u->id, 'work_date' => '2026-10-26', 'name' => 'n', 'service' => 'Edge', 'service_key' => 'edge', 'quantity' => 1]);
        Client::query()->create(['user_id' => $u->id, 'work_date' => '2026-10-25', 'name' => 'n', 'service' => 'Outside', 'service_key' => 'outside', 'quantity' => 1]);
        $s = app(MonthlyReportService::class);
        $p = $s->period('2026-11');
        $text = (new Parser)->parseContent($s->render(app(ReportBuilder::class)->monthlyRows($p['start'], $p['end'])))->getText();
        expect($text)->toContain('ssrf.png">')->toContain('01 Edge')->not->toContain('Outside')
            ->not->toContain('root:x:0');
        expect(config('dompdf.options.enable_remote', false))->toBeFalse()->and(config('dompdf.options.enable_php', false))->toBeFalse();
    });

    it('escapes HTML in the weekly email (HTML part)', function () {
        $e = User::factory()->create(['first_name' => '<script>x</script>']);
        $m = new WeeklyReportsMail('t', ['<script>alert(1)</script> – '.$e->first_name], [], false);
        expect($m->render())->not->toContain('<script>');
    });
});
