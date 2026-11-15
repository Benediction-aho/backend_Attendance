<?php

namespace App\Domain\Reports;

use App\Domain\Attendance\WorkCalendar;
use App\Enums\LogLevel;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\ApiError;
use App\Http\Presenters\EmployeeSummary;
use App\Models\ActivityReport;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\ActivityLogger;
use App\Services\FileUrls;
use App\Settings\SettingsService;
use App\Support\SignupText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Activity reports (SPECS 7, owner request 2026-11-14): a PDF with a title, sent at any time by someone who
 * tracks attendance, delivered at once to the people allowed to read that person's reports. It never
 * replaces the weekly Friday report and is not part of the weekly "received / missing" figures.
 */
class ActivityReportService
{
    /** Tiers that read reports in the admin pages (same as the weekly PDFs). */
    public const READER_PERMISSIONS = [Permission::ViewReports, Permission::ReceiveWeeklyReports];

    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly SettingsService $settings,
        private readonly PdfUpload $pdf,
        private readonly FileUrls $urls,
        private readonly ActivityLogger $logger,
    ) {}

    public static function canRead(User $user): bool
    {
        foreach (self::READER_PERMISSIONS as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** Reports sent today by the user, deleted ones included (deleting does not give a slot back). */
    public function sentToday(User $user): int
    {
        return ActivityReport::withTrashed()->where('user_id', $user->id)->whereDate('work_date', $this->calendar->workDate())->count();
    }

    public function store(User $user, string $title, ?string $note, UploadedFile $file): ActivityReport
    {
        $now = $this->calendar->now();
        $date = $this->calendar->workDate($now);
        $limit = $this->settings->int('activity_reports_per_day', $now);
        $path = 'activity-reports/'.$user->id.'/'.$date.'/'.Str::uuid()->toString().'.pdf';
        $this->pdf->store($file, $path);

        try {
            $report = DB::transaction(function () use ($user, $title, $note, $file, $now, $date, $limit, $path) {
                // Serialize uploads of the same user: the daily limit cannot be passed by parallel requests.
                User::query()->whereKey($user->id)->lockForUpdate()->first();
                if (ActivityReport::withTrashed()->where('user_id', $user->id)->whereDate('work_date', $date)->count() >= $limit) {
                    throw new ApiError(422, "You have reached the limit of {$limit} activity reports for today.", 'too_many_activity_reports', ['limit' => $limit]);
                }

                return ActivityReport::query()->create([
                    'user_id' => $user->id, 'work_date' => $date, 'title' => $title, 'note' => $note, 'path' => $path,
                    'original_name' => PdfUpload::displayName($file), 'size_bytes' => (int) $file->getSize(),
                    'sha256' => (string) hash_file('sha256', (string) $file->getRealPath()), 'submitted_at' => $now,
                ]);
            });
        } catch (Throwable $e) {
            $this->pdf->delete($path);
            throw $e;
        }

        $this->logger->log('reports.activity_submitted', 'Activity report sent: '.$report->title, level: LogLevel::Info, user: $user, actor: $user,
            context: ['activity_report_id' => $report->id, 'work_date' => $date, 'size_bytes' => $report->size_bytes]);
        $this->notifyReaders($user, $report);

        return $report;
    }

    /** Until when the author may delete the report (null: deletion is switched off). */
    public function deletableUntil(ActivityReport $report): ?Carbon
    {
        $minutes = $this->settings->int('activity_report_delete_minutes');

        return $minutes > 0 ? $report->submitted_at->copy()->addMinutes($minutes) : null;
    }

    public function canDelete(ActivityReport $report): bool
    {
        $until = $this->deletableUntil($report);

        return $until !== null && Carbon::now()->lte($until);
    }

    public function delete(User $author, ActivityReport $report): void
    {
        if (! $this->canDelete($report)) {
            throw new ApiError(422, 'This activity report can no longer be deleted.', 'delete_window_closed');
        }
        // The row is kept (soft delete) for the daily limit and the audit trail; the file itself is removed.
        if (ActivityReport::query()->whereKey($report->id)->delete() !== 1) {
            throw new ApiError(404, 'Not found.', 'not_found');
        }
        $this->pdf->delete($report->path);
        $this->logger->log('reports.activity_deleted', 'Activity report deleted by its author: '.$report->title, level: LogLevel::Warning, user: $author, actor: $author,
            context: ['activity_report_id' => $report->id, 'work_date' => $report->work_date->toDateString()]);
    }

    /**
     * The people who may read $author's reports in the admin pages: active super admins, and – for an
     * employee – active admins holding a report tier. Never the author (UserPolicy::manageAttendance).
     *
     * @return Collection<int, User>
     */
    public function readers(User $author): Collection
    {
        $keys = array_map(fn (Permission $p) => $p->value, self::READER_PERMISSIONS);

        return User::query()->where('status', UserStatus::Active->value)
            ->where(fn (Builder $q) => $q->where('role', Role::SuperAdmin->value)
                ->orWhere(fn (Builder $q) => $q->where('role', Role::Admin->value)
                    ->whereHas('permissionRecords', fn (Builder $p) => $p->whereIn('key', $keys))))
            ->orderBy('id')->get()
            ->filter(fn (User $reader) => $reader->can('manageAttendance', $author))->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ActivityReport $r, bool $forAuthor): array
    {
        $out = [
            'id' => $r->id,
            'title' => $r->title,
            'note' => $r->note,
            'work_date' => $r->work_date->toDateString(),
            'file_name' => $r->original_name,
            'size_bytes' => $r->size_bytes,
            'submitted_at' => $r->submitted_at->toIso8601String(),
            'file_url' => $this->urls->temporaryUrl($r->path),
        ];
        if ($forAuthor) {
            return $out + ['can_delete' => $this->canDelete($r), 'delete_until' => $this->deletableUntil($r)?->toIso8601String()];
        }

        return $out + ['user' => $r->user !== null ? EmployeeSummary::of($r->user) : null];
    }

    /** In-app notification to the readers, queued; e-mail only when `activity_report_email` is on. */
    private function notifyReaders(User $author, ActivityReport $report): void
    {
        $readers = $this->readers($author);
        if ($readers->isEmpty()) {
            return;
        }
        // The title goes into notifications and e-mails: it can never become a link.
        Notification::send($readers, new StaffAlert(
            'activity_report',
            'Activity report from '.$author->fullName(),
            $author->fullName().' sent an activity report: '.SignupText::neutralize($report->title),
            ['activity_report_id' => $report->id, 'user_id' => $author->id],
            $this->settings->bool('activity_report_email'),
            '/admin/reports/activity/'.$report->id,
        ));
    }
}
