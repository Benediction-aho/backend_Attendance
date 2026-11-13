<?php

namespace App\Http\Controllers\Attendance;

use App\Domain\Attendance\AttendancePresenter;
use App\Domain\Attendance\AttendanceService;
use App\Domain\Attendance\WorkCalendar;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Exceptions\ApiError;
use App\Http\Presenters\EmployeeSummary;
use App\Models\Attendance;
use App\Models\CheckinAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Admin attendance views and actions; each route checks its own tier, fields are filtered per tier. */
class AdminAttendanceController
{
    public function __construct(
        private readonly AttendanceService $service,
        private readonly WorkCalendar $calendar,
        private readonly AttendancePresenter $presenter,
    ) {}

    /** Every active employee for a date, with what the day means for them and their attendance. */
    public function day(Request $request): JsonResponse
    {
        Gate::authorize('view-attendance');
        $actor = $this->actor($request);
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? $this->calendar->workDate();

        $attendances = Attendance::query()->whereDate('work_date', $date)->get()->keyBy('user_id');
        $employees = User::query()->where('role', Role::Employee->value)
            ->where(fn ($q) => $q->where('status', UserStatus::Active->value)->orWhereIn('id', $attendances->keys()))
            ->orderBy('first_name')->orderBy('last_name')->get();

        return response()->json([
            'date' => $date,
            'server_time' => $this->calendar->now()->toIso8601String(),
            'data' => $employees->map(function (User $u) use ($date, $attendances, $actor) {
                $status = $this->calendar->dayStatus($u, $date);
                /** @var Attendance|null $a */
                $a = $attendances->get($u->id);

                return [
                    'user' => EmployeeSummary::of($u),
                    'day_status' => $status->value,
                    'expected' => $status->isExpected(),
                    'attendance' => $a !== null ? $this->presenter->forAdmin($a, $actor) : null,
                ];
            })->values(),
        ]);
    }

    public function userHistory(Request $request, User $user): JsonResponse
    {
        Gate::authorize('view-attendance');
        $actor = $this->actor($request);
        $this->employeeOnly($user);
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $page = Attendance::query()->where('user_id', $user->id)
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '<=', $v))
            ->orderByDesc('work_date')->paginate(31);

        return response()->json([
            'data' => collect($page->items())->map(fn (Attendance $a) => $this->presenter->forAdmin($a, $actor)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function missions(Request $request): JsonResponse
    {
        $actor = $this->require($request, Permission::ApproveMissionCheckins);
        $data = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected']]);
        $page = Attendance::query()->with('user')->where('check_in_type', Attendance::TYPE_MISSION)
            ->where('mission_status', $data['status'] ?? 'pending')
            ->orderByDesc('check_in_at')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Attendance $a) => $this->presenter->forAdmin($a, $actor) + [
                'user' => $a->user !== null ? EmployeeSummary::of($a->user) : null,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function approveMission(Request $request, Attendance $attendance): JsonResponse
    {
        return $this->decide($request, $attendance, true);
    }

    public function rejectMission(Request $request, Attendance $attendance): JsonResponse
    {
        return $this->decide($request, $attendance, false);
    }

    public function checkInOnBehalf(Request $request, User $user): JsonResponse
    {
        $actor = $this->require($request, Permission::CheckInForEmployee);
        $this->employeeOnly($user);
        if (! $user->isActive()) {
            throw new ApiError(422, 'This account is not active.', 'account_inactive');
        }
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $attendance = $this->service->checkInOnBehalf($actor, $user, $data['reason']);

        return response()->json(['data' => $this->presenter->forAdmin($attendance, $actor)], 201);
    }

    /** Refused check-ins (out of perimeter, low accuracy). */
    public function attempts(Request $request): JsonResponse
    {
        $actor = $this->require($request, Permission::ViewLateness);
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $page = CheckinAttempt::query()->with('user')
            ->whereIn('user_id', User::withTrashed()->select('id')->where('role', Role::Employee->value))
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', (int) $v))
            ->when($data['date'] ?? null, fn ($q, $v) => $q->whereDate('work_date', $v))
            ->orderByDesc('created_at')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (CheckinAttempt $c) => [
                'id' => $c->id,
                'user_id' => $c->user_id,
                'user' => $c->user !== null ? EmployeeSummary::of($c->user) : null,
                'work_date' => $c->work_date->toDateString(),
                'outcome' => $c->outcome,
                'distance_m' => $c->distance_m !== null ? round((float) $c->distance_m, 1) : null,
                'accuracy_m' => $c->accuracy_m !== null ? round((float) $c->accuracy_m, 1) : null,
                'created_at' => $c->created_at->toIso8601String(),
            ] + ($actor->isSuperAdmin() ? ['radius_m' => $c->getAttribute('radius_m'), 'max_accuracy_m' => $c->getAttribute('max_accuracy_m')] : [])),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    private function decide(Request $request, Attendance $attendance, bool $approve): JsonResponse
    {
        $actor = $this->require($request, Permission::ApproveMissionCheckins);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        if ($attendance->check_in_type !== Attendance::TYPE_MISSION) {
            throw new ApiError(404, 'Not found.');
        }
        if ($attendance->user_id === $actor->id) {
            throw new ApiError(403, 'This action is not allowed.');
        }
        $attendance = $this->service->decideMission($actor, $attendance, $approve, $data['note'] ?? null);

        return response()->json(['data' => $this->presenter->forAdmin($attendance, $actor)]);
    }

    private function require(Request $request, Permission $permission): User
    {
        $actor = $this->actor($request);
        if (! $actor->hasPermission($permission)) {
            throw new ApiError(403, 'This action is not allowed.');
        }

        return $actor;
    }

    private function employeeOnly(User $user): void
    {
        if ($user->role !== Role::Employee) {
            throw new ApiError(403, 'This action is not allowed.');
        }
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
