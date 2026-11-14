<?php

namespace App\Mail;

use App\Services\FileUrls;
use Illuminate\Mail\Mailables\Attachment;

/** Monthly performance report PDF (SPECS 7), email only. */
class MonthlyReportMail extends SimpleMail
{
    public function __construct(public string $path, public string $fileName, public string $periodLabel) {}

    protected function subjectLine(): string
    {
        return 'Monthly Performance Report by Employee';
    }

    protected function body(): array
    {
        return [
            'greeting' => 'Hello,',
            'lines' => ["Please find attached the lateness and client performance report by employee for the period {$this->periodLabel}."],
        ];
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromStorageDisk(FileUrls::disk(), $this->path)->as($this->fileName)->withMime('application/pdf')];
    }
}
