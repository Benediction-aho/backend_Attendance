<?php

namespace App\Domain\Reports;

use App\Domain\Attendance\AttendanceService;
use App\Domain\Attendance\WorkCalendar;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\WeeklyReportSubmission;
use App\Settings\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Weekly PDF report window (SPECS 7): report day (default Friday) from weekly_window_start to
 * weekly_window_end. Who is expected, which week an upload belongs to and whether it is late.
 */
class WeeklyWindow
{
    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly SettingsService $settings,
        private readonly AttendanceService $attendance,
    ) {}

    public function weekStart(string $date): string
    {
        return Carbon::parse($date, $this->calendar->timezone())->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    /** Report day of the week starting on $weekStart. */
    public function windowDate(string $weekStart): string
    {
        $noon = $this->calendar->at($weekStart, '12:00');

        return Carbon::parse($weekStart)->addDays($this->settings->int('weekly_window_day', $noon) - 1)->toDateString();
    }

    public function opensAt(string $windowDate): Carbon
    {
        return $this->calendar->at($windowDate, $this->settings->string('weekly_window_start', $this->calendar->at($windowDate, '12:00')));
    }

    public function closesAt(string $windowDate): Carbon
    {
        return $this->calendar->at($windowDate, $this->settings->string('weekly_window_end', $this->calendar->at($windowDate, '12:00')));
    }

    public function reminderAt(string $windowDate): Carbon
    {
        return $this->calendar->at($windowDate, $this->settings->string('weekly_reminder_time', $this->calendar->at($windowDate, '12:00')));
    }

    /** Active employees/interns expected on the report day (not on leave, day off, holiday). */
    public function isExpected(User $user, string $windowDate): bool
    {
        return $user->role === Role::Employee && $user->status === UserStatus::Active
            && $this->calendar->dayStatus($user, $windowDate)->isExpected();
    }

    /** @return Collection<int, User> */
    public function expectedEmployees(string $windowDate): Collection
    {
        $users = User::query()->where('role', Role::Employee->value)->where('status', UserStatus::Active->value)
            ->orderByRaw('upper(last_name), upper(first_name), id')->get()
            ->filter(fn (User $u) => $this->calendar->wasMemberOn($u, $windowDate))->values();
        $statuses = $this->calendar->dayStatuses($users, $windowDate);

        return $users->filter(fn (User $u) => $statuses[$u->id]->isExpected())->values();
    }

    /**
     * Grace (SPECS 7): an employee absent on the report day (not expected, or – setting – expected but
     * without check-in) may submit until the window end time of their next expected day without being late.
     */
    public function deadlineFor(User $user, string $windowDate): Carbon
    {
        $close = $this->closesAt($windowDate);
        $absent = ! $this->calendar->dayStatus($user, $windowDate)->isExpected()
            || ($this->settings->bool('weekly_absent_friday_grace', $close) && $this->attendance->forDate($user, $windowDate) === null);
        if (! $absent) {
            return $close;
        }
        $d = Carbon::parse($windowDate);
        for ($i = 0; $i < 31; $i++) {
            $d->addDay();
            if ($this->calendar->dayStatus($user, $d->toDateString())->isExpected()) {
                return $this->closesAt($d->toDateString());
            }
        }

        return $close;
    }

    /**
     * Week an upload made now belongs to: the current week once its window has opened; before that,
     * the previous week while it has no report yet. Null = nothing can be submitted now.
     *
     * @return array{week_start: string, window_date: string}|null
     */
    public function targetWeek(User $user, Carbon $now): ?array
    {
        $current = $this->weekStart($this->calendar->workDate($now));
        $window = $this->windowDate($current);
        if ($now->gte($this->opensAt($window))) {
            return ['week_start' => $current, 'window_date' => $window];
        }
        $previous = Carbon::parse($current)->subWeek()->toDateString();
        if ($user->created_at === null || $user->created_at->gt($this->closesAt($this->windowDate($previous)))) {
            return null; // account created after that week's window: nothing to catch up
        }
        $hasPrevious = WeeklyReportSubmission::query()->where('user_id', $user->id)->whereDate('week_start', $previous)->exists();

        return $hasPrevious ? null : ['week_start' => $previous, 'window_date' => $this->windowDate($previous)];
    }
}
