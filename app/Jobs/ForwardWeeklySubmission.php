<?php

namespace App\Jobs;

use App\Domain\Reports\WeeklyReportService;
use App\Models\WeeklyReportSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * A weekly report received after the Friday close is forwarded directly to the authorized staff
 * (SPECS 7 "Retardataires"). Per-recipient delivery records make a retry skip those already served.
 */
class ForwardWeeklySubmission implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $submissionId) {}

    public function handle(WeeklyReportService $service): void
    {
        $submission = WeeklyReportSubmission::query()->with('user')->find($this->submissionId);
        if ($submission === null || $submission->forwarded_at !== null) {
            return;
        }
        $name = $submission->user->fullName();
        $title = $submission->is_late ? "Late weekly report from {$name}" : "Weekly report from {$name}";
        $body = "{$name} sent the weekly report for the week of {$submission->week_start->format('j M Y')}"
            .($submission->version > 1 ? " (version {$submission->version})" : '').($submission->is_late ? ', after the deadline.' : '.');
        $service->deliver('weekly_forward', (string) $submission->id, $title, $body, '/admin/reports/weekly/submissions/'.$submission->id, collect([$submission]));
        $submission->update(['forwarded_at' => Carbon::now()]);
    }
}
