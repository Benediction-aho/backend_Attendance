<?php

namespace App\Domain\Reports;

use App\Domain\Attendance\WorkCalendar;
use App\Enums\LogLevel;
use App\Exceptions\ApiError;
use App\Http\Controllers\Reports\AdminReportController;
use App\Http\Presenters\EmployeeSummary;
use App\Jobs\ForwardWeeklySubmission;
use App\Mail\WeeklyReportsMail;
use App\Models\User;
use App\Models\WeeklyReportSubmission;
use App\Notifications\ReportNotice;
use App\Services\ActivityLogger;
use App\Services\FileUrls;
use App\Settings\SettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Weekly PDF uploads (versions, late flag) and their delivery to authorized staff. */
class WeeklyReportService
{
    public function __construct(
        private readonly WeeklyWindow $window,
        private readonly WorkCalendar $calendar,
        private readonly SettingsService $settings,
        private readonly ReportRecipients $recipients,
        private readonly ReportBuilder $builder,
        private readonly ActivityLogger $logger,
        private readonly FileUrls $urls,
    ) {}

    /** Real PDF check: magic bytes at the start of the file (the client-declared type is not trusted). */
    public static function isPdf(UploadedFile $file): bool
    {
        $handle = @fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 1024);
        fclose($handle);

        return str_starts_with($head, '%PDF-');
    }

    /** Owner decision: password-protected PDFs are refused. */
    public static function isEncrypted(UploadedFile $file): bool
    {
        return PdfActiveContentScanner::isEncrypted((string) @file_get_contents((string) $file->getRealPath()));
    }

    /** P5-03 / P5-09 / P5-10: structural active-content scan (see PdfActiveContentScanner). */
    public static function hasActiveContent(UploadedFile $file): bool
    {
        return PdfActiveContentScanner::hasActiveContent((string) @file_get_contents((string) $file->getRealPath()));
    }

    public function store(User $user, UploadedFile $file): WeeklyReportSubmission
    {
        $now = $this->calendar->now();
        $target = $this->window->targetWeek($user, $now);
        if ($target === null) {
            $current = $this->window->windowDate($this->window->weekStart($this->calendar->workDate($now)));
            throw new ApiError(422, 'The weekly report window is not open yet.', 'window_not_open', ['opens_at' => $this->window->opensAt($current)->toIso8601String()]);
        }
        $deadline = $this->window->deadlineFor($user, $target['window_date']);
        $disk = Storage::disk(FileUrls::disk());
        $path = 'weekly-reports/'.$user->id.'/'.$target['week_start'].'/'.Str::uuid()->toString().'.pdf';
        $disk->putFileAs(dirname($path), $file, basename($path), ['ContentType' => 'application/pdf']);

        try {
            $submission = DB::transaction(function () use ($user, $file, $target, $now, $deadline, $path) {
                // Serialize uploads of the same user (version numbers, single current row).
                User::query()->whereKey($user->id)->lockForUpdate()->first();
                $last = WeeklyReportSubmission::query()->where('user_id', $user->id)->whereDate('week_start', $target['week_start'])->max('version');
                $version = ((int) $last) + 1;
                if ($version > $this->settings->int('weekly_report_max_versions')) {
                    throw new ApiError(422, 'You have replaced this report too many times. Please contact an administrator.', 'too_many_versions');
                }
                WeeklyReportSubmission::query()->where('user_id', $user->id)->whereDate('week_start', $target['week_start'])
                    ->where('is_current', true)->update(['is_current' => false]);

                $name = trim((string) preg_replace('/[^\w .()-]+/u', '_', $file->getClientOriginalName()));

                return WeeklyReportSubmission::query()->create([
                    'user_id' => $user->id, 'week_start' => $target['week_start'], 'window_date' => $target['window_date'],
                    'version' => $version, 'is_current' => true, 'path' => $path,
                    'original_name' => mb_substr($name !== '' ? $name : 'report.pdf', 0, 200),
                    'size_bytes' => (int) $file->getSize(), 'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()),
                    'submitted_at' => Carbon::now(), 'is_late' => $now->gt($deadline),
                ]);
            });
        } catch (Throwable $e) {
            $disk->delete($path);
            throw $e;
        }

        $this->logger->log('reports.weekly_submitted', "Weekly report submitted (version {$submission->version})".($submission->is_late ? ', late' : ''),
            level: $submission->is_late ? LogLevel::Warning : LogLevel::Info, user: $user, actor: $user,
            context: ['submission_id' => $submission->id, 'week_start' => $target['week_start'], 'late' => $submission->is_late]);

        // Received after the Friday close: forwarded directly to the authorized staff (in-app + email).
        // P5-07: only the first version received after the close is forwarded; later replacements of the same
        // employee-week are not re-sent (visible in the application, current version + history).
        $close = $this->window->closesAt($target['window_date']);
        $firstAfterClose = ! WeeklyReportSubmission::query()->where('user_id', $user->id)->whereDate('week_start', $target['week_start'])
            ->where('id', '!=', $submission->id)->where('submitted_at', '>', $close)->exists();
        if ($now->gt($close) && $firstAfterClose) {
            ForwardWeeklySubmission::dispatch($submission->id)->afterCommit();
        }

        return $submission;
    }

    /** @return array<string, mixed> */
    public function present(WeeklyReportSubmission $s, bool $withUser = false): array
    {
        return [
            'id' => $s->id,
            'week_start' => $s->week_start->toDateString(),
            'window_date' => $s->window_date->toDateString(),
            'version' => $s->version,
            'is_current' => $s->is_current,
            'file_name' => $s->original_name,
            'size_bytes' => $s->size_bytes,
            'submitted_at' => $s->submitted_at->toIso8601String(),
            'is_late' => $s->is_late,
            'forwarded_at' => $s->forwarded_at?->toIso8601String(),
            'file_url' => $this->urls->temporaryUrl($s->path),
        ] + ($withUser ? ['user' => EmployeeSummary::of($s->user)] : []);
    }

    /**
     * P5-05: attachments are Base64-encoded in the email (×4/3, plus line breaks and MIME headers). They are
     * attached only if the raw total stays under email_attachments_max_mb AND the encoded size plus a margin
     * for the body stays under the provider's per-email limit (mail.provider_max_mb, Resend: 40 MB).
     */
    public function fitsInEmail(int $rawBytes): bool
    {
        $mb = 1024 * 1024;
        $encoded = (int) ceil($rawBytes * 4 / 3 * 1.03) + (int) config('mail.size_margin_bytes', $mb);

        return $rawBytes <= $this->settings->int('email_attachments_max_mb') * $mb
            && $encoded <= (int) config('mail.provider_max_mb', 40) * $mb;
    }

    /**
     * UX5-03 / UX5-10: "Ama Mensah – Accountant (ama@…)": same "First Last" order as the app; job title always,
     * email only for recipients allowed to list accounts. (The monthly PDF keeps the template "LAST First".)
     */
    public static function missingLine(User $u, bool $withEmail): string
    {
        $title = trim((string) $u->job_title);

        return $u->fullName().($title !== '' ? ' – '.$title : '').($withEmail ? ' ('.$u->email.')' : '');
    }

    /**
     * Sends one notification + email per recipient, recording each delivery (a retry skips those done).
     *
     * @param  Collection<int, WeeklyReportSubmission>  $submissions
     * @param  Collection<int, User>|null  $recipients
     * @param  Collection<int, User>|null  $missing  expected employees without a report (listed one per line)
     */
    public function deliver(string $kind, string $ref, string $title, string $body, string $path, Collection $submissions, ?Collection $recipients = null, ?Collection $missing = null): int
    {
        // The email lists client names: only accounts allowed to see weekly client lists receive it (P5-02).
        $recipients = ($recipients ?? $this->recipients->weekly())
            ->filter(fn (User $u) => AdminReportController::canSeeWeeklyClients($u))->values();
        $attach = $this->fitsInEmail((int) $submissions->sum('size_bytes'));
        $lines = [$body];
        foreach ($submissions as $s) {
            /** @var WeeklyReportSubmission $s */
            $week = $this->builder->weekly($s->user, $s->week_start->toDateString());
            $lines[] = $s->user->fullName().($s->is_late ? ' (late)' : '').' – clients this week: '.$week['clients_count'];
            foreach ($week['clients'] as $c) {
                $lines[] = "  • {$c['name']} – {$c['service']} × {$c['quantity']}";
            }
        }
        if (! $attach && $submissions->isNotEmpty()) {
            $lines[] = 'The reports are too large to be attached: open each one in the application (sign-in required).';
        }

        $ids = array_values($submissions->map(fn (WeeklyReportSubmission $s) => $s->id)->all());
        $failed = 0;
        $sent = 0;
        foreach ($recipients as $recipient) {
            $claimed = DB::table('report_deliveries')->insertOrIgnore(['kind' => $kind, 'ref' => $ref, 'user_id' => $recipient->id, 'sent_at' => Carbon::now()]);
            if ($claimed !== 1) {
                continue;
            }
            $mailLines = $lines;
            if ($missing !== null && $missing->isNotEmpty()) {
                $withEmail = Gate::forUser($recipient)->allows('viewAny', User::class);
                $list = ['Not received ('.$missing->count().'):'];
                foreach ($missing as $m) {
                    $list[] = '  • '.self::missingLine($m, $withEmail);
                }
                array_splice($mailLines, 1, 0, $list);
            }
            try {
                $recipient->notifyNow(new ReportNotice($kind, $title, $body, $path, ['submission_ids' => $ids]));
                // The delivery row is a claim; delivered_at is set by the mail itself once actually sent, and a
                // permanent failure of the attachment version falls back to the link-only version (P5-05).
                Mail::to($recipient->email)->queue((new WeeklyReportsMail($title, $mailLines, $ids, $attach))->forDelivery($kind, $ref, $recipient->id));
                $sent++;
            } catch (Throwable $e) {
                DB::table('report_deliveries')->where(['kind' => $kind, 'ref' => $ref, 'user_id' => $recipient->id])->delete();
                $failed++;
                report($e);
            }
        }
        if ($failed > 0) {
            throw new RuntimeException("Report delivery failed for {$failed} recipient(s)");
        }

        return $sent;
    }
}
