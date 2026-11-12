<?php

namespace App\Mail;

use App\Models\WeeklyReportSubmission;
use App\Services\FileUrls;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

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

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    /** report_deliveries row this email fulfils (kind, ref, recipient id), if any. */
    public ?string $deliveryKind = null;

    public ?string $deliveryRef = null;

    public ?int $deliveryUserId = null;

    public function forDelivery(string $kind, string $ref, int $userId): static
    {
        $this->deliveryKind = $kind;
        $this->deliveryRef = $ref;
        $this->deliveryUserId = $userId;

        return $this;
    }

    /**
     * P5-05: the per-recipient delivery is marked done only once the provider accepted the email.
     *
     * @param  MailFactory|Mailer  $mailer
     */
    public function send($mailer): ?SentMessage
    {
        $sent = parent::send($mailer);
        if ($this->deliveryKind !== null) {
            DB::table('report_deliveries')->where(['kind' => $this->deliveryKind, 'ref' => $this->deliveryRef, 'user_id' => $this->deliveryUserId])
                ->update(['delivered_at' => Carbon::now()]);
        }

        return $sent;
    }

    /** P5-05: after the last retry, an email with attachments (e.g. refused as too large) is re-sent without them. */
    public function failed(Throwable $e): void
    {
        if (! $this->attach) {
            return;
        }
        $fallback = new self($this->title, [...$this->lines, 'The reports could not be attached: open each one in the application (sign-in required).'], $this->submissionIds, false);
        if ($this->deliveryKind !== null && $this->deliveryRef !== null && $this->deliveryUserId !== null) {
            $fallback->forDelivery($this->deliveryKind, $this->deliveryRef, $this->deliveryUserId);
        }
        Mail::to(array_column($this->to, 'address'))->queue($fallback);
    }

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
