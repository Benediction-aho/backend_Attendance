<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LogLevel;
use App\Enums\LogType;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Settings\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only access to the activity log (SPECS 9 bis). There is no route to edit or delete entries.
 */
class ActivityLogController
{
    public function index(Request $request, SettingsService $settings): JsonResponse
    {
        $f = $this->filters($request, true);
        $page = $this->query($request, $settings, $f)->paginate((int) ($f['per_page'] ?? 50));

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

    /**
     * CSV export for super admins (phase 6): same filters as the viewer, streamed in chunks (constant memory),
     * capped at log_export_max_rows, and logged. Cells starting with = + - @ are neutralized (CSV injection).
     */
    public function export(Request $request, SettingsService $settings, ActivityLogger $logger): StreamedResponse
    {
        $f = $this->filters($request, false);
        $max = $settings->int('log_export_max_rows');
        $query = $this->query($request, $settings, $f);
        $total = (clone $query)->count();
        /** @var User $viewer */
        $viewer = $request->user();
        $logger->security('logs.exported', 'Activity log exported as CSV ('.min($total, $max).' rows).', LogLevel::Info, $viewer, $viewer,
            context: ['filters' => array_filter($f, fn ($v) => $v !== null), 'rows' => min($total, $max), 'truncated' => $total > $max]);
        $tz = (string) config('app.timezone');
        $name = 'activity_log_'.Carbon::now($tz)->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($query, $max, $tz) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet apps
            fputcsv($out, ['Date', 'Type', 'Level', 'Event', 'Description', 'User', 'User email', 'Actor', 'IP', 'Device', 'User agent', 'Context'], escape: '');
            $written = 0;
            foreach ($query->lazy(500) as $log) {
                if ($written >= $max) {
                    break;
                }
                /** @var ActivityLog $log */
                fputcsv($out, array_map(self::cell(...), [
                    $log->created_at->setTimezone($tz)->format('Y-m-d H:i:s'),
                    $log->type->value, $log->level->value, $log->event, $log->description,
                    $log->user?->fullName(), $log->user?->email, $log->actor?->fullName(),
                    $log->ip, $log->device_id, $log->user_agent,
                    $log->context ? json_encode($log->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
                ]), escape: '');
                if (++$written % 500 === 0) {
                    flush();
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public static function cell(mixed $v): string
    {
        $s = (string) ($v ?? '');

        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$s : $s;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request, bool $paginated): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::in(array_map(fn ($t) => $t->value, LogType::cases()))],
            'level' => ['nullable', Rule::in(array_map(fn ($l) => $l->value, LogLevel::cases()))],
            'event' => ['nullable', 'string', 'max:80', 'regex:/^[a-z_.]+$/'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ] + ($paginated ? ['per_page' => ['nullable', 'integer', 'min:1', 'max:200']] : []));
    }

    /**
     * @param  array<string, mixed>  $f
     * @return Builder<ActivityLog>
     */
    private function query(Request $request, SettingsService $settings, array $f): Builder
    {
        $tz = (string) config('app.timezone');
        /** @var User $viewer */
        $viewer = $request->user();
        // Super admin events (sign-ins, IPs, email changes) are visible to super admins only, unless configured (P1-11).
        $hideSuperAdmins = ! $viewer->isSuperAdmin() && ! $settings->bool('logs_admins_see_super_admin_events');
        $superAdminIds = fn ($q) => $q->select('id')->from('users')->where('role', Role::SuperAdmin->value);

        return ActivityLog::query()
            ->when($hideSuperAdmins, fn ($q) => $q->where(fn ($w) => $w->whereNull('user_id')->orWhereNotIn('user_id', $superAdminIds)))
            ->with(['user:id,first_name,last_name,email,deleted_at', 'actor:id,first_name,last_name,deleted_at'])
            ->when($f['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($f['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['level'] ?? null, fn ($q, $v) => $q->where('level', $v))
            ->when($f['event'] ?? null, fn ($q, $v) => $q->where('event', 'like', str_replace('_', '\\_', (string) $v).'%'))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', (string) $v, $tz)?->startOfDay()))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', (string) $v, $tz)?->endOfDay()))
            ->orderByDesc('created_at')->orderByDesc('id');
    }
}
