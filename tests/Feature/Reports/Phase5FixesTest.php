<?php

// Regression tests for docs/reports/phase-5-security.md (P5-01 … P5-08).

use App\Domain\Reports\WeeklyReportService;
use App\Enums\Permission;
use App\Mail\WeeklyReportsMail;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyReportSubmission;
use App\Settings\SettingDefinitions;
use App\Settings\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    at('2026-11-16 09:00');
    $this->u = User::factory()->create(['created_at' => Carbon::parse('2026-01-01')]);
    attend($this->u, '2026-11-16');
});

function p5Pdf(string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent('r.pdf', "%PDF-1.4\n".$body."\n%%EOF\n");
}

describe('P5-01 control characters', function () {
    it('rejects NUL and control characters in JSON, form and query input', function () {
        Sanctum::actingAs($this->u);
        foreach (["a\u{0000}b", "a\u{0007}b", "a\u{001B}[31m", "a\u{007F}"] as $bad) {
            $this->postJson('/api/me/tasks', ['title' => $bad])->assertStatus(422)->assertJsonPath('code', 'invalid_characters');
            $this->postJson('/api/me/clients', ['name' => 'n', 'service' => $bad, 'quantity' => 1])->assertStatus(422);
        }
        $this->postJson('/api/me/clients', ['name' => 'n', 'service' => 's', 'quantity' => 1, "k\u{0000}" => 'x'])->assertStatus(422);
        $this->getJson('/api/clients/services?q=a%00b')->assertStatus(422);
        // P5-11: C1 controls (U+0080-U+009F) are rejected too, in bodies and queries.
        foreach (["a\u{0085}b", "a\u{009B}31m", "\u{0080}"] as $bad) {
            $this->postJson('/api/me/tasks', ['title' => $bad])->assertStatus(422)->assertJsonPath('code', 'invalid_characters');
        }
        $this->getJson('/api/clients/services?q=a%C2%85b')->assertStatus(422);
        expect(Task::query()->count())->toBe(0)->and(Client::query()->count())->toBe(0);
    });

    it('accepts tab and newlines and stores exactly what it returns', function () {
        Sanctum::actingAs($this->u);
        $r = $this->postJson('/api/me/tasks', ['title' => "Line\tone\r\nline two \u{00A0}\u{00E9}\u{20AC}"])->assertStatus(201);
        expect(Task::query()->findOrFail($r->json('data.id'))->title)->toBe($r->json('data.title'));
    });
});

describe('P5-02 weekly client names', function () {
    beforeEach(function () {
        Client::query()->create(['user_id' => $this->u->id, 'work_date' => '2026-11-16', 'name' => 'CLIENT-NAME', 'service' => 'Visa', 'service_key' => 'visa', 'quantity' => 1]);
        $this->sub = WeeklyReportSubmission::query()->create(['user_id' => $this->u->id, 'week_start' => '2026-11-16', 'window_date' => '2026-11-20', 'version' => 1,
            'path' => 'weekly-reports/x.pdf', 'original_name' => 'x.pdf', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'submitted_at' => now(), 'is_late' => false]);
    });

    it('applies one rule: view_clients or receive_weekly_reports (or super admin) see names, view_reports alone does not', function () {
        $cases = [
            [User::factory()->admin(Permission::ViewReports)->create(), false],
            [User::factory()->admin(Permission::ReceiveWeeklyReports)->create(), true],
            [User::factory()->admin(Permission::ViewReports, Permission::ViewClients)->create(), true],
            [User::factory()->superAdmin()->create(), true],
        ];
        foreach ($cases as [$admin, $sees]) {
            Sanctum::actingAs($admin);
            $sub = $this->getJson("/api/admin/reports/weekly/submissions/{$this->sub->id}")->assertOk();
            expect(str_contains($sub->getContent(), 'CLIENT-NAME'))->toBe($sees)
                ->and($sub->json('data.week.clients_count'))->toBe(1);
            if ($admin->hasPermission(Permission::ViewReports)) {
                $w = $this->getJson("/api/admin/users/{$this->u->id}/reports/weekly?date=2026-11-16")->assertOk()->getContent();
                expect(str_contains($w, 'CLIENT-NAME'))->toBe($sees);
            }
        }
    });

    it('never emails the client list to an account outside the rule', function () {
        $viewer = User::factory()->admin(Permission::ViewReports)->create();
        $receiver = User::factory()->admin(Permission::ReceiveWeeklyReports)->create();
        app(WeeklyReportService::class)->deliver('weekly_forward', 'x', 'T', 'B', '/p', collect([$this->sub->load('user')]), collect([$viewer, $receiver]));
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($receiver->email) && collect($m->lines)->contains(fn ($l) => str_contains($l, 'CLIENT-NAME')));
        Mail::assertNotQueued(WeeklyReportsMail::class, fn ($m) => $m->hasTo($viewer->email));
    });
});

describe('P5-03 PDF hardening', function () {
    beforeEach(fn () => attend($this->u, '2026-11-20'));

    it('rejects PDFs with JavaScript, launch actions or embedded files (also #xx-escaped names)', function () {
        at('2026-11-20 16:05');
        Sanctum::actingAs($this->u);
        foreach (['<< /S /JavaScript /JS (app.alert(1)) >>', '<< /OpenAction << /S /Launch /F (cmd.exe) >> >>', '<< /Names << /EmbeddedFiles 3 0 R >> >>', '<< /S /J#61vaScript >>', '<< /JS 4 0 R >>'] as $body) {
            $this->postJson('/api/me/reports/weekly/submission', ['file' => p5Pdf($body)])->assertStatus(422)->assertJsonPath('code', 'pdf_active_content');
        }
        $this->postJson('/api/me/reports/weekly/submission', ['file' => p5Pdf('<< /Type /Catalog /JSONData 1 >>')])->assertStatus(201);
    });

    it('rejects password-protected PDFs (trailer, incremental update, xref stream, escaped name) with pdf_encrypted', function () {
        at('2026-11-20 16:05');
        Sanctum::actingAs($this->u);
        $obj = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $samples = [
            $obj."trailer\n<< /Root 1 0 R /Encrypt << /Filter /Standard /V 1 /R 2 /O (x) /U (y) /P -4 >> >>",
            $obj."trailer\n<< /Root 1 0 R >>\nstartxref\n0\n%%EOF\ntrailer\n<< /Root 1 0 R /Prev 0 /Encrypt 5 0 R >>",
            $obj."9 0 obj\n<< /Type /XRef /Size 10 /W [1 2 1] /Root 1 0 R /Encrypt 5 0 R /Length 0 >>\nstream\n\nendstream\nendobj",
            $obj."trailer\n<< /Root 1 0 R /En#63rypt 5 0 R >>",
        ];
        foreach ($samples as $body) {
            $this->postJson('/api/me/reports/weekly/submission', ['file' => p5Pdf($body)])
                ->assertStatus(422)->assertJsonPath('code', 'pdf_encrypted')
                ->assertJsonPath('message', 'Password-protected PDFs are not accepted. Please export the report without a password.');
        }
        $this->postJson('/api/me/reports/weekly/submission', ['file' => p5Pdf($obj."trailer\n<< /Root 1 0 R /EncryptionNote 1 >>")])->assertStatus(201);
    });

    it('serves files as attachments with a safe name and a sandbox CSP', function () {
        at('2026-11-20 16:05');
        Sanctum::actingAs($this->u);
        $url = $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload('../<b>x</b>.html')])->json('data.file_url');
        $res = $this->get($url)->assertOk();
        expect($res->headers->get('Content-Disposition'))->toBe('attachment; filename="report.pdf"')
            ->and((string) $res->headers->get('Content-Security-Policy'))->toContain('sandbox')->toContain("default-src 'none'");
        // Other API responses keep the baseline policy.
        expect((string) $this->getJson('/api/me/tasks')->headers->get('Content-Security-Policy'))->not->toContain('sandbox');
    });
});

describe('P5-04 / P5-07 weekly close and late forwards', function () {
    it('attaches the latest version submitted before the close, not a later replacement', function () {
        User::factory()->superAdmin()->create();
        attend($this->u, '2026-11-20');
        Sanctum::actingAs($this->u);
        at('2026-11-20 16:10');
        $v1 = $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->json('data.id');
        at('2026-11-20 17:00:30');
        $v2 = $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->json('data.id');
        at('2026-11-20 17:01');
        $this->artisan('reports:weekly-close')->assertSuccessful();
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => str_contains($m->title, '1 of 1') && $m->submissionIds === [$v1]);
        // v2 is the first post-close version: forwarded once; a third version is not re-sent.
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => $m->submissionIds === [$v2]);
        at('2026-11-20 18:00');
        $v3 = $this->postJson('/api/me/reports/weekly/submission', ['file' => pdfUpload()])->json('data.id');
        Mail::assertNotQueued(WeeklyReportsMail::class, fn ($m) => $m->submissionIds === [$v3]);
    });
});

describe('P5-05 email size and delivery', function () {
    it('compares the Base64-encoded size with the provider limit', function () {
        $svc = app(WeeklyReportService::class);
        $mb = 1024 * 1024;
        expect($svc->fitsInEmail(20 * $mb))->toBeTrue();
        app(SettingsService::class)->set(['email_attachments_max_mb' => 28], null, Carbon::parse('2000-01-01'));
        expect($svc->fitsInEmail(28 * $mb))->toBeTrue();
        config(['mail.provider_max_mb' => 30]);
        expect($svc->fitsInEmail(25 * $mb))->toBeFalse()->and($svc->fitsInEmail(20 * $mb))->toBeTrue();
        // The setting itself cannot exceed what fits under Resend's 40 MB once encoded.
        expect(SettingDefinitions::all()['email_attachments_max_mb']['default'])->toBe(20);
        expect(Validator::make(['v' => 29], ['v' => SettingDefinitions::all()['email_attachments_max_mb']['rules']])->fails())->toBeTrue();
    });

    it('marks a delivery done only once sent, and falls back to the link-only email on permanent failure', function () {
        $admin = User::factory()->admin(Permission::ReceiveWeeklyReports)->create();
        $sub = WeeklyReportSubmission::query()->create(['user_id' => $this->u->id, 'week_start' => '2026-11-16', 'window_date' => '2026-11-20', 'version' => 1,
            'path' => 'weekly-reports/x.pdf', 'original_name' => 'x.pdf', 'size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'submitted_at' => now(), 'is_late' => false]);
        Storage::disk('local')->put('weekly-reports/x.pdf', "%PDF-1.4\n%%EOF");
        app(WeeklyReportService::class)->deliver('weekly_forward', 'r1', 'T', 'B', '/p', collect([$sub->load('user')]));
        $row = fn () => DB::table('report_deliveries')->where(['kind' => 'weekly_forward', 'ref' => 'r1', 'user_id' => $admin->id])->first();
        expect($row())->not->toBeNull()->and($row()->delivered_at)->toBeNull();

        /** @var WeeklyReportsMail $mail */
        $mail = Mail::queued(WeeklyReportsMail::class)->first();
        expect($mail->attach)->toBeTrue();
        Mail::fake(); // fresh fake: the next queued mail is the fallback
        $mail->failed(new RuntimeException('552 message too large'));
        Mail::assertQueued(WeeklyReportsMail::class, fn ($m) => ! $m->attach && $m->hasTo($admin->email) && $m->deliveryRef === 'r1'
            && collect($m->lines)->contains(fn ($l) => str_contains($l, 'could not be attached')));

        // Actually sending (array transport) marks the delivery as done.
        config(['mail.default' => 'array']);
        $mail->send(app('mail.manager')->mailer('array'));
        expect($row()->delivered_at)->not->toBeNull();
    });
});

describe('P5-08 upload size', function () {
    it('keeps the PDF size setting within the PHP upload limits of the image', function () {
        $ini = (string) file_get_contents(base_path('docker/php.ini'));
        preg_match('/upload_max_filesize\s*=\s*(\d+)M/', $ini, $u);
        preg_match('/post_max_size\s*=\s*(\d+)M/', $ini, $p);
        $rules = SettingDefinitions::all()['report_pdf_max_mb']['rules'];
        $max = (int) str_replace('max:', '', (string) collect($rules)->first(fn ($r) => is_string($r) && str_starts_with($r, 'max:')));
        expect((int) $u[1])->toBeGreaterThanOrEqual($max)->and((int) $p[1])->toBeGreaterThan($max);
    });
});
