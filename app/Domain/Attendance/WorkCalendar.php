<?php

namespace App\Domain\Attendance;

use App\Models\Holiday;
use App\Models\Leave;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Settings\SettingsService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Server-side calendar (FIABILITE B2): the only place that turns "now" into a work date and
 * decides whether an employee is expected on a date (working days, schedule, holidays, leave).
 */
class WorkCalendar
{
    public function __construct(private readonly SettingsService $settings) {}

    public function timezone(): string
    {
        return $this->settings->string('timezone');
    }

    /** Current server time in the business time zone. */
    public function now(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    /** Work date (Y-m-d) of an instant: its calendar date in the business time zone. */
    public function workDate(?CarbonInterface $at = null): string
    {
        return Carbon::instance($at ?? Carbon::now())->setTimezone($this->timezone())->toDateString();
    }

    /** Instant of a "HH:MM" setting on a work date, in the business time zone. */
    public function at(string $date, string $time): Carbon
    {
        return Carbon::parse($date.' '.$time, $this->timezone())->startOfMinute();
    }

    /** ISO weekday (1 = Monday … 7 = Sunday) of a work date. */
    public function weekday(string $date): int
    {
        return Carbon::parse($date, $this->timezone())->dayOfWeekIso;
    }

    public function isWorkingDay(string $date): bool
    {
        $at = $this->at($date, '12:00');

        return in_array($this->weekday($date), $this->settings->intList('working_days', $at), true);
    }

    public function scheduleFor(User $user, string $date): ?WorkSchedule
    {
        return WorkSchedule::query()->where('user_id', $user->id)->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->first();
    }

    public function holiday(string $date): ?Holiday
    {
        return Holiday::query()->where('date', $date)->first();
    }

    public function leave(User $user, string $date): ?Leave
    {
        return Leave::query()->where('user_id', $user->id)
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->first();
    }

    /**
     * UX5-02: whether the account already belonged to the staff on a date (approved, or created when no
     * approval date is recorded, on or before it). Past runs (catch-ups) must not count later accounts.
     */
    public function wasMemberOn(User $user, string $date): bool
    {
        $since = $user->approved_at ?? $user->created_at;

        return $since === null || Carbon::instance($since)->setTimezone($this->timezone())->toDateString() <= $date;
    }

    public function dayStatus(User $user, string $date): DayStatus
    {
        return $this->resolveStatus($date, $this->holiday($date) !== null, $this->leave($user, $date) !== null, $this->scheduleFor($user, $date));
    }

    /**
     * P6-05: day status of many employees on one date with a constant number of queries (holiday, leaves,
     * latest schedules: 3 queries whatever the number of users). Used by dashboards, reminders and reports.
     *
     * @param  iterable<User>  $users
     * @return array<int, DayStatus> keyed by user id
     */
    public function dayStatuses(iterable $users, string $date): array
    {
        $ids = [];
        foreach ($users as $u) {
            $ids[] = $u->id;
        }
        if ($ids === []) {
            return [];
        }
        $holiday = Holiday::query()->where('date', $date)->exists();
        $onLeave = $holiday ? [] : array_flip(Leave::query()->whereIn('user_id', $ids)
            ->where('start_date', '<=', $date)->where('end_date', '>=', $date)->distinct()->pluck('user_id')->all());
        $schedules = WorkSchedule::query()->whereIn('user_id', $ids)->where('effective_from', '<=', $date)
            ->select('work_schedules.*')->distinct('user_id')
            ->orderBy('user_id')->orderByDesc('effective_from')->orderByDesc('id')->get()->keyBy('user_id');
        $out = [];
        foreach ($ids as $id) {
            /** @var WorkSchedule|null $schedule */
            $schedule = $schedules->get($id);
            $out[$id] = $this->resolveStatus($date, $holiday, isset($onLeave[$id]), $schedule);
        }

        return $out;
    }

    private function resolveStatus(string $date, bool $holiday, bool $leave, ?WorkSchedule $schedule): DayStatus
    {
        if ($holiday) {
            return DayStatus::Holiday;
        }
        if ($leave) {
            return DayStatus::Leave;
        }
        if (! $this->isWorkingDay($date)) {
            return DayStatus::NonWorking;
        }
        $weekday = $this->weekday($date);
        if ($schedule !== null && in_array($weekday, $schedule->days_off, true)) {
            return DayStatus::DayOff;
        }
        if ($schedule !== null && in_array($weekday, $schedule->remote_days, true)) {
            return DayStatus::Remote;
        }

        return DayStatus::Working;
    }

    /**
     * Instant at which a still-open attendance of $date is closed automatically. An auto check-out time
     * earlier than the end of the working day (default 00:00) means the night after the work date.
     */
    public function autoCheckoutAt(string $date): Carbon
    {
        $noon = $this->at($date, '12:00');
        $auto = $this->settings->string('auto_checkout_time', $noon);
        $end = $this->settings->string('end_of_day_time', $noon);
        $instant = $this->at($date, $auto);

        return $auto <= $end ? $instant->addDay() : $instant;
    }
}
