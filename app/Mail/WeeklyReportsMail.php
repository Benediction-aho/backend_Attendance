<?php

namespace App\Mail;

use App\Models\WeeklyReportSubmission;
use App\Services\FileUrls;
use Illuminate\Mail\Mailables\Attachment;

/**
 * Weekly PDF reports for authorized staff (SPECS 7 "Suivi des envois"): the PDFs are attached while the
 * total stays under email_attachments_max_mb; otherwise the email links to each report in the application
 * (sign-in required), never to a public file.
 */
class WeeklyReportsMail extends SimpleMail
{
    /**
     * @param  list<string>  $lines
     * @param  list<int>  $submissionIds
     */
    public function __construct(public string $title, public array $lines, public array $submissionIds, public bool $attach) {}

    protected function subjectLine(): string
    {
        return $this->title;
    }

    protected function body(): array
    {
        return [
            'greeting' => 'Hello,',
            'lines' => $this->lines,
            'actionUrl' => self::frontendUrl('/admin/reports/weekly'),
            'actionText' => 'Open the weekly reports',
        ];
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if (! $this->attach) {
            return [];
        }
        $out = [];
        foreach (WeeklyReportSubmission::query()->with('user')->whereIn('id', $this->submissionIds)->orderBy('id')->get() as $s) {
            $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', mb_strtoupper($s->user->last_name).'_'.$s->user->first_name);
            $out[] = Attachment::fromStorageDisk(FileUrls::disk(), $s->path)
                ->as("Weekly_report_{$name}_{$s->week_start->toDateString()}.pdf")->withMime('application/pdf');
        }

        return $out;
    }
}
