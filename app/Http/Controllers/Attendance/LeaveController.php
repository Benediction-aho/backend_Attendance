<?php

namespace App\Http\Controllers\Attendance;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\ApiError;
use App\Http\Presenters\EmployeeSummary;
use App\Models\Leave;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Employee leave (SPECS 6 bis), declared by admins holding "manage_leaves". */
class LeaveController
{
    public function __construct(private readonly ActivityLogger $logger) {}

    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => Leave::query()->where('user_id', $user->id)->orderByDesc('start_date')->limit(100)->get()
            ->map(fn (Leave $l) => $this->row($l, false))->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $page = Leave::query()->with('user')
            ->whereIn('user_id', User::withTrashed()->select('id')->where('role', Role::Employee->value))
            ->when($data['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', (int) $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->whereDate('end_date', '>=', $v))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->whereDate('start_date', '<=', $v))
            ->orderByDesc('start_date')->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (Leave $l) => $this->row($l, true)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        $actor = $this->actor($request);
        if ($user->role !== Role::Employee || ! $user->isActive()) {
            throw new ApiError(403, 'This action is not allowed.');
        }
        $data = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $leave = DB::transaction(function () use ($user, $data, $actor) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $overlap = Leave::query()->where('user_id', $user->id)
                ->whereDate('start_date', '<=', $data['end_date'])->whereDate('end_date', '>=', $data['start_date'])->exists();
            if ($overlap) {
                throw new ApiError(422, 'This leave overlaps another leave of the employee.', 'leave_overlap');
            }

            return Leave::query()->create([
                'user_id' => $user->id, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
                'note' => $data['note'] ?? null, 'created_by' => $actor->id,
            ]);
        });
        $this->logger->log('leave.created', 'Leave declared', LogType::Action, LogLevel::Info, $user, $actor, [
            'leave_id' => $leave->id, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
        ]);

        return response()->json(['data' => $this->row($leave, true)], 201);
    }

    public function destroy(Request $request, Leave $leave): JsonResponse
    {
        $actor = $this->actor($request);
        $employee = User::withTrashed()->find($leave->user_id);
        if ($employee === null || $employee->role !== Role::Employee) {
            throw new ApiError(403, 'This action is not allowed.');
        }
        $leave->delete();
        $this->logger->log('leave.deleted', 'Leave removed', LogType::Action, LogLevel::Info, $employee, $actor, [
            'leave_id' => $leave->id, 'start_date' => $leave->start_date->toDateString(), 'end_date' => $leave->end_date->toDateString(),
        ]);

        return response()->json(null, 204);
    }

    private function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();
        if (! $actor->hasPermission(Permission::ManageLeaves)) {
            throw new ApiError(403, 'This action is not allowed.');
        }

        return $actor;
    }

    /** @return array<string, mixed> */
    private function row(Leave $l, bool $admin): array
    {
        $row = ['id' => $l->id, 'start_date' => $l->start_date->toDateString(), 'end_date' => $l->end_date->toDateString(), 'note' => $l->note];

        return $admin ? $row + ['user_id' => $l->user_id, 'user' => $l->user !== null ? EmployeeSummary::of($l->user) : null, 'created_by' => $l->created_by] : $row;
    }
}
