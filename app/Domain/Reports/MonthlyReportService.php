<?php

namespace App\Domain\Reports;

use App\Domain\Attendance\WorkCalendar;
use App\Models\MonthlyReport;
use App\Services\FileUrls;
use App\Settings\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Monthly "Lateness and Client Performance Report by Employee" (SPECS 7), reproducing
 * docs/modeles/Rapport_de_performance_Octobre_2026.pdf with English content.
 */
class MonthlyReportService
{
    /** File names keep the French month name (SPECS 7: Rapport_de_performance_Octobre_2026.pdf). */
    public const FRENCH_MONTHS = [1 => 'Janvier', 'Fevrier', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Aout', 'Septembre', 'Octobre', 'Novembre', 'Decembre'];

    public function __construct(
        private readonly ReportBuilder $builder,
        private readonly WorkCalendar $calendar,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Period of the report sent in $month (YYYY-MM): report day of the previous month → the day before the report day in $month,
     * both inclusive (default 26 → 25), so consecutive reports never count a day twice.
     *
     * @return array{start: string, end: string}
     */
    public function period(string $month): array
    {
        $end = Carbon::createFromFormat('Y-m-d', $month.'-01', $this->calendar->timezone());
        if ($end === null) {
            throw new \InvalidArgumentException('Invalid month');
        }
        $day = $this->settings->int('monthly_report_day', $end->copy()->setTime(12, 0));
        $start = $end->copy()->subMonthNoOverflow()->day($day);

        return ['start' => $start->toDateString(), 'end' => $end->copy()->day($day)->subDay()->toDateString()];
    }

    public static function fileName(string $month): string
    {
        [$year, $m] = array_map('intval', explode('-', $month));

        return 'Rapport_de_performance_'.self::FRENCH_MONTHS[$m].'_'.$year.'.pdf';
    }

    /** @param list<array{user_id: int, employee: string, detail: string|null, late: int, clients: int, services: list<array{service: string, service_key: string, quantity: int}>}> $rows */
    public function render(array $rows): string
    {
        return Pdf::loadView('pdf.monthly', ['rows' => $rows])->setPaper('a4')->output();
    }

    /** Generates (or regenerates on a retried run) the month's PDF in private storage. */
    public function generate(string $month): MonthlyReport
    {
        $period = $this->period($month);
        $pdf = $this->render($this->builder->monthlyRows($period['start'], $period['end']));
        $path = "monthly-reports/{$month}.pdf";
        Storage::disk(FileUrls::disk())->put($path, $pdf);

        return MonthlyReport::query()->updateOrCreate(['month' => $month], [
            'period_start' => $period['start'], 'period_end' => $period['end'], 'path' => $path,
            'file_name' => self::fileName($month), 'generated_at' => Carbon::now(),
        ]);
    }

    public static function twoDigits(int $n): string
    {
        return $n === 0 ? '-' : str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }
}
