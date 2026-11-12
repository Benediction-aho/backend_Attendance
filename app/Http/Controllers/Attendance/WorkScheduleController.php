<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\WorkCalendar;
use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\ApiError;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\ActivityLogger;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Days off and remote days per contract (SPECS 6 bis): the employee selects them once; afterwards only
 * an admin holding "manage_work_schedules" changes them. Versioned by effective date (past never rewritten).
 */
class WorkScheduleController
{
    public function __construct(
        private readonly WorkCalendar $calendar,
        private readonly SettingsService $settings,
        private readonly ActivityLogger $logger,
    ) {}

    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->payload($user));
    }

    public function storeMine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        [$daysOff, $remote] = $this->validated($request);

        $today = $this->calendar->workDate();
        // The lock prevents two parallel first selections; the existence check makes the choice one-time.
        DB::transaction(function () use ($user, $daysOff, $remote, $today) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (WorkSchedule::query()->where('user_id', $user->id)->exists()) {
                throw new ApiError(409, 'Your days off and remote days are already set. Ask an administrator to change them.', 'schedule_locked');
            }
            WorkSchedule::query()->create([
                'user_id' => $user->id, 'effective_from' => $today, 'days_off' => $daysOff, 'remote_days' => $remote,
                'set_by' => $user->id, 'set_by_employee' => true,
            ]);
        });
        $this->logger->log('schedule.selected', 'Days off and remote days selected', LogType::Action, LogLevel::Info, $user, $user, ['days_off' => $daysOff, 'remote_days' => $remote]);

        return response()->json($this->payload($user), 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request, $user, false);

        return response()->json($this->payload($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $this->authorizeAdmin($request, $user, true);
        [$daysOff, $remote] = $this->validated($request);
        $today = $this->calendar->workDate();
        $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today]]);
        $from = (string) ($request->input('effective_from') ?? $today);

        WorkSchedule::query()->updateOrCreate(
            ['user_id' => $user->id, 'effective_from' => $from],
            ['days_off' => $daysOff, 'remote_days' => $remote, 'set_by' => $actor->id, 'set_by_employee' => false],
        );
        $this->logger->log('schedule.updated', 'Days off and remote days changed by an admin', LogType::Action, LogLevel::Info, $user, $actor, [
            'days_off' => $daysOff, 'remote_days' => $remote, 'effective_from' => $from,
        ]);

        return response()->json($this->payload($user));
    }

    /** @return array{0: list<int>, 1: list<int>} */
    private function validated(Request $request): array
    {
        $working = $this->settings->intList('working_days');
        $max = $this->settings->int('max_days_off_per_week');
        $data = $request->validate([
            'days_off' => ['present', 'array', 'max:'.$max],
            'days_off.*' => ['integer', 'distinct', 'in:'.implode(',', $working)],
            'remote_days' => ['present', 'array', 'max:7'],
            'remote_days.*' => ['integer', 'distinct', 'in:'.implode(',', $working)],
        ], [
            'days_off.max' => "You can choose at most {$max} days off per week.",
        ]);
        $daysOff = array_values(array_map('intval', $data['days_off']));
        $remote = array_values(array_map('intval', $data['remote_days']));
        sort($daysOff);
        sort($remote);
        if (array_intersect($daysOff, $remote) !== []) {
            throw new ApiError(422, 'A day cannot be both a day off and a remote day.', 'schedule_overlap');
        }

        return [$daysOff, $remote];
    }

    private function authorizeAdmin(Request $request, User $target, bool $write): User
    {
        /** @var User $actor */
        $actor = $request->user();
        $allowed = $write
            ? $actor->hasPermission(Permission::ManageWorkSchedules)
            : $actor->hasPermission(Permission::ManageWorkSchedules) || $actor->can('view-attendance');
        if (! $allowed || $target->role !== Role::Employee) {
            throw new ApiError(403, 'This action is not allowed.');
        }

        return $actor;
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        $today = $this->calendar->workDate();
        $current = $this->calendar->scheduleFor($user, $today);
        $upcoming = WorkSchedule::query()->where('user_id', $user->id)->where('effective_from', '>', $today)->orderBy('effective_from')->get();

        return [
            'selected' => WorkSchedule::query()->where('user_id', $user->id)->exists(),
            'locked' => WorkSchedule::query()->where('user_id', $user->id)->exists(),
            'max_days_off_per_week' => $this->settings->int('max_days_off_per_week'),
            'working_days' => $this->settings->intList('working_days'),
            'current' => $current !== null ? $this->row($current) : null,
            'upcoming' => $upcoming->map(fn (WorkSchedule $s) => $this->row($s))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function row(WorkSchedule $s): array
    {
        return [
            'effective_from' => $s->effective_from->toDateString(),
            'days_off' => $s->days_off,
            'remote_days' => $s->remote_days,
            'set_by_employee' => $s->set_by_employee,
        ];
    }
}
