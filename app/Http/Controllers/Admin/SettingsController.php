<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LogLevel;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\SettingHistory;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\ActivityLogger;
use App\Settings\SettingDefinitions;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingsController
{
    public function __construct(private readonly SettingsService $settings, private readonly ActivityLogger $logger) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return $this->listing($actor);
    }

    /**
     * Keys the user may see and change: settings of their sections; super-admin-only settings for super admins.
     *
     * @return list<string>
     */
    private function allowedKeys(User $user): array
    {
        $sections = $user->settingsSections();

        return array_keys(array_filter(SettingDefinitions::all(), fn (array $d) => in_array($d['section'], $sections, true)
            && (! $d['super_admin_only'] || $user->isSuperAdmin())));
    }

    private function listing(User $user): JsonResponse
    {
        return response()->json([
            'data' => $this->settings->describe($this->allowedKeys($user)),
            'sections' => $user->settingsSections(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $definitions = SettingDefinitions::all();
        $today = Carbon::now()->toDateString();

        $rules = [
            'values' => ['required', 'array', 'min:1'],
            'effective_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today],
        ];
        foreach ($definitions as $key => $definition) {
            // Lists may be emptied (present) when their own rules allow it; scalars are required.
            $rules["values.$key"] = ['sometimes', $definition['type'] === 'int_list' ? 'present' : 'required', ...$definition['rules']];
            if ($definition['type'] === 'int_list') {
                $rules["values.$key.*"] = $key === 'monthly_report_recipient_ids'
                    // Only active admins and super admins may receive the monthly report (P1-09).
                    ? ['integer', 'distinct', Rule::exists('users', 'id')->whereIn('role', [Role::Admin->value, Role::SuperAdmin->value])
                        ->where('status', UserStatus::Active->value)->whereNull('deleted_at')]
                    : ($definition['item_rules'] ?? ['integer', 'min:1', 'max:1440']);
            }
        }
        /** @var User $actor */
        $actor = $request->user();
        $requested = array_keys((array) $request->input('values'));
        // All-or-nothing: one key outside the caller's sections refuses the whole change (before validation).
        if (array_diff(array_intersect($requested, array_keys($definitions)), $this->allowedKeys($actor)) !== []) {
            abort(403, 'You are not allowed to change one or more of these settings.');
        }

        $data = $request->validate($rules);

        /** @var array<string, mixed> $values */
        $values = (array) $request->input('values');
        $unknown = array_diff(array_keys($values), array_keys($definitions));
        $readOnly = array_filter(array_keys($values), fn ($k) => isset($definitions[$k]) && ($definitions[$k]['editable'] ?? true) === false);
        if ($unknown !== [] || $readOnly !== []) {
            throw ValidationException::withMessages(['values' => ['One or more settings cannot be changed.']]);
        }

        $effective = isset($data['effective_from']) && $data['effective_from'] !== $today
            ? Carbon::createFromFormat('Y-m-d', $data['effective_from'])?->startOfDay()
            : Carbon::now();

        $this->settings->set($values, $actor, $effective);

        $this->logger->log('settings.updated', 'Configuration changed: '.implode(', ', array_keys($values)), level: LogLevel::Warning, actor: $actor, context: [
            'keys' => array_keys($values),
            'effective_from' => $effective?->toIso8601String(),
        ]);

        if (! $actor->isSuperAdmin()) {
            $labels = array_map(fn (string $k) => $definitions[$k]['label'], array_keys($values));
            Notification::send(User::query()->activeSuperAdmins()->get(), new StaffAlert(
                'settings_changed',
                'Configuration changed by an admin',
                "{$actor->fullName()} changed: ".implode('; ', $labels).'.',
                ['keys' => array_keys($values), 'actor_id' => $actor->id],
                path: '/admin/settings',
            ));
        }

        return $this->listing($actor);
    }

    public function history(Request $request): JsonResponse
    {
        $request->validate(['key' => ['nullable', 'string', 'max:80']]);

        /** @var User $actor */
        $actor = $request->user();
        $page = SettingHistory::query()
            ->whereIn('key', $this->allowedKeys($actor))
            ->with('changer:id,first_name,last_name')
            ->when($request->filled('key'), fn ($q) => $q->where('key', $request->string('key')->toString()))
            ->orderByDesc('changed_at')->orderByDesc('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (SettingHistory $h) => [
                'key' => $h->key,
                'old_value' => $h->old_value,
                'new_value' => $h->new_value,
                'effective_from' => $h->effective_from->toIso8601String(),
                'changed_at' => $h->changed_at->toIso8601String(),
                'changed_by' => $h->changer ? ['id' => $h->changer->id, 'name' => $h->changer->fullName()] : null,
            ]),
            'sections' => $actor->settingsSections(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
