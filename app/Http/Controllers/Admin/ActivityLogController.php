<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Read-only access to the activity log (SPECS 9 bis). There is no route to edit or delete entries.
 */
class ActivityLogController
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(array_map(fn ($t) => $t->value, LogType::cases()))],
            'level' => ['nullable', Rule::in(array_map(fn ($l) => $l->value, LogLevel::cases()))],
            'event' => ['nullable', 'string', 'max:80', 'regex:/^[a-z_.]+$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $tz = (string) config('app.timezone');

        $page = ActivityLog::query()
            ->with(['user:id,first_name,last_name,email,deleted_at', 'actor:id,first_name,last_name,deleted_at'])
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['level'] ?? null, fn ($q, $v) => $q->where('level', $v))
            ->when($f['event'] ?? null, fn ($q, $v) => $q->where('event', 'like', str_replace('_', '\\_', (string) $v).'%'))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', (string) $v, $tz)?->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', (string) $v, $tz)?->endOfDay()))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at->toIso8601String(),
                'type' => $log->type->value,
                'level' => $log->level->value,
                'event' => $log->event,
                'description' => $log->description,
                'user' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->fullName(), 'email' => $log->user->email, 'deleted' => $log->user->deleted_at !== null] : null,
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->fullName()] : null,
                'context' => $log->context,
                'ip' => $log->ip,
                'device_id' => $log->device_id,
                'user_agent' => $log->user_agent,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
