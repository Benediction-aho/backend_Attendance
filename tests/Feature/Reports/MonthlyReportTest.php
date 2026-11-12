<?php

use App\Domain\Reports\MonthlyReportService;
use App\Domain\Reports\ReportBuilder;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Mail\MonthlyReportMail;
use App\Models\Client;
use App\Models\MonthlyReport;
use App\Models\ScheduledRun;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
    at('2026-10-01 08:00');
    ScheduledRun::query()->create(['task' => 'monthly_report', 'run_key' => 'monthly_report:2026-10', 'status' => 'succeeded', 'started_at' => now()]);
});

function pdfText(string $bytes): string
{
    return (new Parser)->parseContent($bytes)->getText();
}

function client(User $u, string $date, string $service, int $qty = 1): void
{
    Client::query()->create(['user_id' => $u->id, 'work_date' => $date, 'name' => 'C', 'service' => $service, 'service_key' => Client::serviceKey($service), 'quantity' => $qty]);
}

it('covers the 26th of the previous month to the 26th of the current month inclusive', function () {
    $s = app(MonthlyReportService::class);
    expect($s->period('2026-11'))->toBe(['start' => '2026-10-26', 'end' => '2026-11-26'])
        ->and($s->period('2027-01'))->toBe(['start' => '2026-12-26', 'end' => '2027-01-26'])
        ->and($s->period('2027-03'))->toBe(['start' => '2027-02-26', 'end' => '2027-03-26'])
        ->and(MonthlyReportService::fileName('2026-10'))->toBe('Rapport_de_performance_Octobre_2026.pdf')
        ->and(MonthlyReportService::fileName('2026-08'))->toBe('Rapport_de_performance_Aout_2026.pdf');
});

it('computes rows from known data (lateness, clients, services grouped)', function () {
    $ngari = User::factory()->create(['first_name' => 'Ludmilla', 'last_name' => 'Ngari']);
    $fofana = User::factory()->create(['first_name' => 'Fosty', 'last_name' => 'Fofana']);
    $blocked = User::factory()->status(UserStatus::Blocked)->create(['first_name' => 'Old', 'last_name' => 'Aaa']);
    foreach (['2026-10-25', '2026-10-26', '2026-11-02', '2026-11-26', '2026-11-27'] as $d) {
        attend($ngari, $d, true);
    }
    attend($fofana, '2026-11-03', false);
    client($ngari, '2026-10-26', 'Residence Permit');
    client($ngari, '2026-11-26', 'residence  permit', 1);
    client($ngari, '2026-11-10', 'Visa', 3);
    client($ngari, '2026-10-25', 'Visa', 9);   // before the period
    client($ngari, '2026-11-27', 'Visa', 9);   // after the period

    $rows = app(ReportBuilder::class)->monthlyRows('2026-10-26', '2026-11-26');
    expect(array_column($rows, 'employee'))->toBe(['FOFANA Fosty', 'NGARI Ludmilla'])
        ->and($rows[1]['late'])->toBe(3)->and($rows[1]['clients'])->toBe(3)
        ->and($rows[1]['services'])->toBe([
            ['service' => 'Visa', 'service_key' => 'visa', 'quantity' => 3],
            ['service' => 'Residence Permit', 'service_key' => 'residence permit', 'quantity' => 2],
        ])
        ->and($rows[0]['late'])->toBe(0)->and($rows[0]['clients'])->toBe(0);
    expect(collect($rows)->pluck('user_id'))->not->toContain($blocked->id);
});

it('renders the PDF like the template (English content, formatting)', function () {
    $pdf = app(MonthlyReportService::class)->render([
        ['user_id' => 1, 'employee' => 'NGARI Ludmilla', 'late' => 6, 'clients' => 2, 'services' => [['service' => 'Residence Permit', 'service_key' => 'residence permit', 'quantity' => 2], ['service' => 'Visa', 'service_key' => 'visa', 'quantity' => 11]]],
        ['user_id' => 2, 'employee' => 'FOFANA Fosty', 'late' => 5, 'clients' => 0, 'services' => []],
    ]);
    expect($pdf)->toStartWith('%PDF-');
    $text = preg_replace('/\s+/', ' ', pdfText($pdf));
    foreach (['ASSISTANCE GHANA', 'by GIMA Services', 'Lateness and Client Performance Report by Employee', 'Employee', 'Late arrivals', 'Clients', 'Client services',
        'NGARI Ludmilla', '02 Residence Permit', '11 Visa', 'FOFANA Fosty', 'Monthly Performance Report by Employee'] as $needle) {
        expect($text)->toContain($needle);
    }
    expect($text)->toMatch('/FOFANA Fosty 5 - -/')->not->toContain('Rapport')->not->toContain('Period');
    // Late count in red (#FF0000 → "1 0 0 rg" fill colour in the content stream).
    $html = view('pdf.monthly', ['rows' => [['user_id' => 1, 'employee' => 'X', 'late' => 6, 'clients' => 0, 'services' => []]]])->render();
    expect($html)->toContain('td.late { color: #FF0000')->toContain('<td class="late">6</td>');
});

it('is sent on the 26th at 19:00 to super admins and selected admins only, once, with an in-app notice', function () {
    $super = User::factory()->superAdmin()->create();
    $selected = User::factory()->admin()->create();
    $notSelected = User::factory()->admin(Permission::ViewReports, Permission::ReceiveWeeklyReports)->create();
    $blockedSelected = User::factory()->admin()->status(UserStatus::Blocked)->create();
    $employee = User::factory()->create(['first_name' => 'Ludmilla', 'last_name' => 'Ngari']);
    app(SettingsService::class)->set(['monthly_report_recipient_ids' => [$selected->id]]);
    DB::table('settings')->where('key', 'monthly_report_recipient_ids')->update(['value' => json_encode([$selected->id, $blockedSelected->id, $employee->id])]);
    app(SettingsService::class)->flush();
    attend($employee, '2026-11-26', true);
    client($employee, '2026-11-26', 'Residence Permit', 2);

    at('2026-11-26 18:59:59');
    $this->artisan('reports:monthly')->assertSuccessful();
    Mail::assertNothingQueued();

    at('2026-11-26 19:00');
    $this->artisan('reports:monthly')->assertSuccessful();
    $this->artisan('reports:monthly')->assertSuccessful();
    Mail::assertQueuedCount(2);
    Mail::assertQueued(MonthlyReportMail::class, fn ($m) => $m->hasTo($super->email) && $m->fileName === 'Rapport_de_performance_Novembre_2026.pdf');
    Mail::assertQueued(MonthlyReportMail::class, fn ($m) => $m->hasTo($selected->email));
    foreach ([$notSelected, $blockedSelected, $employee] as $u) {
        Mail::assertNotQueued(MonthlyReportMail::class, fn ($m) => $m->hasTo($u->email));
    }
    expect(DB::table('notifications')->where('data->kind', 'monthly_report_emailed')->pluck('notifiable_id')->sort()->values()->all())
        ->toBe(collect([$super->id, $selected->id])->sort()->values()->all());

    $report = MonthlyReport::query()->where('month', '2026-11')->firstOrFail();
    $text = preg_replace('/\s+/', ' ', pdfText(Storage::disk('local')->get($report->path)));
    expect($text)->toContain('NGARI Ludmilla 1 01 02 Residence Permit');
    $mail = new MonthlyReportMail($report->path, $report->file_name, 'x');
    expect($mail->attachments())->toHaveCount(1);
});

it('is caught up after a missed run (within report_catchup_days) and never sent twice', function () {
    User::factory()->superAdmin()->create();
    at('2026-11-28 09:00');
    $this->artisan('reports:monthly')->assertSuccessful();
    Mail::assertQueuedCount(1);
    ScheduledRun::query()->where('run_key', 'monthly_report:2026-11')->update(['status' => 'failed']);
    $this->artisan('reports:monthly')->assertSuccessful(); // retried run: per-recipient record prevents a second email
    Mail::assertQueuedCount(1);

    at('2026-12-10 09:00');
    ScheduledRun::query()->where('run_key', 'monthly_report:2026-11')->delete();
    $this->artisan('reports:monthly')->assertSuccessful();
    expect(ScheduledRun::query()->where('run_key', 'monthly_report:2026-11')->exists())->toBeFalse(); // too old: skipped
});

it('uses the configured day and time', function () {
    User::factory()->superAdmin()->create();
    app(SettingsService::class)->set(['monthly_report_day' => 20, 'monthly_report_time' => '20:30']);
    at('2026-11-20 20:29');
    $this->artisan('reports:monthly')->assertSuccessful();
    Mail::assertNothingQueued();
    at('2026-11-20 20:30');
    $this->artisan('reports:monthly')->assertSuccessful();
    Mail::assertQueuedCount(1);
    expect(MonthlyReport::query()->where('month', '2026-11')->value('period_start')->toDateString())->toBe('2026-10-20');
});
