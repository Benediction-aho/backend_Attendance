<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\ReportBuilder;
use App\Exceptions\ApiError;
use App\Models\Task;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Employee tasks (SPECS 7): planned in the morning or written when done; editable at any time. */
class TaskController
{
    public function __construct(private readonly WorkCalendar $calendar, private readonly ReportBuilder $builder, private readonly SettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? $this->calendar->workDate();
        $tasks = Task::query()->where('user_id', $this->user($request)->id)->whereDate('work_date', $date)->orderBy('id')->get();

        return response()->json(['work_date' => $date, 'data' => $tasks->map(fn (Task $t) => $t->present())->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'done' => ['sometimes', 'boolean'],
        ]);
        $user = $this->user($request);
        $date = $this->calendar->workDate();
        $title = trim((string) preg_replace('/\s+/u', ' ', $data['title']));
        if ($title === '') {
            throw new ApiError(422, 'Please describe the task.', 'title_required');
        }
        if (Task::query()->where('user_id', $user->id)->whereDate('work_date', $date)->count() >= $this->settings->int('tasks_max_per_day')) {
            throw new ApiError(422, 'You have reached the maximum number of tasks for today.', 'too_many_tasks');
        }
        $done = (bool) ($data['done'] ?? false);
        $task = Task::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'title' => $title,
            'status' => $done ? 'done' : 'planned', 'completed_at' => $done ? Carbon::now() : null,
        ]);
        $this->builder->refreshIfGenerated($user, $date);

        return response()->json(['data' => $task->present()], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $user = $this->user($request);
        $this->own($user, $task);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:500'],
            'done' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('title', $data)) {
            $title = trim((string) preg_replace('/\s+/u', ' ', $data['title']));
            if ($title === '') {
                throw new ApiError(422, 'Please describe the task.', 'title_required');
            }
            $task->title = $title;
        }
        if (array_key_exists('done', $data)) {
            if ($data['done'] && $task->status !== 'done') {
                $task->status = 'done';
                $task->completed_at = Carbon::now(); // completion time = server time (FIABILITE B2)
            } elseif (! $data['done'] && $task->status === 'done') {
                $task->status = 'planned';
                $task->completed_at = null;
            }
        }
        $task->save();
        $this->builder->refreshIfGenerated($user, $task->work_date->toDateString());

        return response()->json(['data' => $task->present()]);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $user = $this->user($request);
        $this->own($user, $task);
        $date = $task->work_date->toDateString();
        $task->delete();
        $this->builder->refreshIfGenerated($user, $date);

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function own(User $user, Task $task): void
    {
        if ($task->user_id !== $user->id) {
            throw new ApiError(404, 'Not found.', 'not_found'); // no existence oracle on other users' tasks
        }
    }
}
