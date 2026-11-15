<?php

namespace App\Domain\Attendance;

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The one place that answers "who tracks attendance" and "whose attendance may this person see or manage"
 * (SPECS 2 and 6, owner decision 2026-11-14):
 * - employees always track attendance; admins track it like employees from the date in the setting
 *   `admin_attendance_starts_on` (empty: never); super admins never do;
 * - in the admin pages an admin sees and manages employees only (never another admin, never themselves);
 *   only a super admin sees and manages admins; nobody manages a super admin's attendance (there is none).
 *
 * No retroactive effect: a date is judged with the setting as it was in force on that date, so changing
 * the setting later never rewrites which past days counted (FIABILITE B2).
 */
class AttendanceScope
{
    public const ADMIN_START_SETTING = 'admin_attendance_starts_on';

    /** Activity log events describing someone's attendance: hidden from admins when they are about an admin. */
    public const LOG_EVENT_PREFIXES = ['attendance.', 'faceid.', 'schedule.', 'leave.', 'reports.'];

    public function __construct(private readonly SettingsService $settings, private readonly WorkCalendar $calendar) {}

    /**
     * Date from which admins track attendance, as configured on $date (default: today); null = admins do not.
     * A value saved during a day applies to that whole day (the setting is read at the end of the work date).
     */
    public function adminStartDate(?string $date = null): ?string
    {
        $date ??= $this->calendar->workDate();

        return $this->settings->date(self::ADMIN_START_SETTING, Carbon::parse($date, $this->calendar->timezone())->endOfDay());
    }

    public function adminsTrackOn(string $date): bool
    {
        $start = $this->adminStartDate($date);

        return $start !== null && $date >= $start;
    }

    /** Whether the account (by its role) tracks attendance on $date (default: today). Status is not considered. */
    public function tracksOn(User $user, ?string $date = null): bool
    {
        return match ($user->role) {
            Role::Employee => true,
            Role::Admin => $this->adminTracksOn($user, $date ?? $this->calendar->workDate()),
            Role::SuperAdmin => false,
        };
    }

    /**
     * CHG-04: an admin who already checked in on a date keeps tracking attendance for that date even if the
     * start date is emptied or moved later during the day (check-out, own history and reports keep working).
     */
    private function adminTracksOn(User $user, string $date): bool
    {
        return $this->adminsTrackOn($date)
            || Attendance::query()->where('user_id', $user->id)->whereDate('work_date', $date)->exists();
    }

    /**
     * Roles that track attendance on $date.
     *
     * @return list<string>
     */
    public function rolesOn(string $date): array
    {
        return $this->adminsTrackOn($date) ? [Role::Employee->value, Role::Admin->value] : [Role::Employee->value];
    }

    /**
     * Roles whose attendance $viewer may see and manage in the admin pages (null: the system itself, e.g. a
     * scheduled job). The single rule behind UserPolicy::manageAttendance and every admin list and total.
     *
     * @return list<string>
     */
    public static function rolesVisibleTo(?User $viewer): array
    {
        return match (true) {
            $viewer === null, $viewer->isSuperAdmin() => [Role::Employee->value, Role::Admin->value],
            $viewer->isAdmin() => [Role::Employee->value],
            default => [],
        };
    }

    /**
     * Roles that track attendance on $date among those $viewer may see.
     *
     * @return list<string>
     */
    public function rolesVisibleOn(?User $viewer, string $date): array
    {
        return array_values(array_intersect($this->rolesOn($date), self::rolesVisibleTo($viewer)));
    }

    /**
     * People of the attendance pages for $date, as $viewer may see them: active accounts whose role tracks
     * attendance on that date, plus accounts having data that day ($withData), always within the roles the
     * viewer may see.
     *
     * @param  iterable<int, int|string>  $withData  user ids
     * @return Builder<User>
     */
    public function population(?User $viewer, string $date, iterable $withData = []): Builder
    {
        $tracked = $this->rolesVisibleOn($viewer, $date);
        $ids = array_values(is_array($withData) ? $withData : iterator_to_array($withData, false));

        return User::query()->whereIn('role', self::rolesVisibleTo($viewer))
            ->where(function (Builder $q) use ($tracked, $ids) {
                $q->where(fn (Builder $q) => $q->whereIn('role', $tracked)->where('status', UserStatus::Active->value));
                if ($ids !== []) {
                    $q->orWhereIn('id', $ids);
                }
            });
    }

    /**
     * Limits rows keyed by a user id (attendances, leaves, attempts, devices, reports…) to the accounts whose
     * attendance $viewer may see. Deleted accounts keep their history.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function visibleRows(Builder $query, ?User $viewer, string $column = 'user_id'): Builder
    {
        return $query->whereIn($column, User::withTrashed()->select('id')->whereIn('role', self::rolesVisibleTo($viewer)));
    }

    /**
     * Who is told about an attendance event of $subject (new Face ID device, mission check-in, early check-out,
     * working week…): for an employee, the active holders of $permission (super admins included); for an admin,
     * active super admins only. Never the subject.
     *
     * @return Collection<int, User>
     */
    public function alertRecipients(User $subject, Permission $permission): Collection
    {
        $query = $subject->role === Role::Employee
            ? User::query()->holdingPermission($permission)
            : User::query()->activeSuperAdmins();

        return $query->where('id', '!=', $subject->id)->orderBy('id')->get();
    }
}
