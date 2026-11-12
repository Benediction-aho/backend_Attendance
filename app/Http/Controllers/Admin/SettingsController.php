<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LogLevel;
use App\Models\SettingHistory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Settings\SettingDefinitions;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SettingsController
{
    public function __construct(private readonly SettingsService $settings, private readonly ActivityLogger $logger) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->settings->describe()]);
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
            $rules["values.$key"] = ['sometimes', 'required', ...$definition['rules']];
            if ($definition['type'] === 'int_list') {
                $rules["values.$key.*"] = $key === 'monthly_report_recipient_ids'
                    ? ['integer', 'exists:users,id']
                    : ['integer', 'min:1', 'max:1440'];
            }
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

        /** @var User $actor */
        $actor = $request->user();
        $this->settings->set($values, $actor, $effective);

        $this->logger->log('settings.updated', 'Configuration changed: '.implode(', ', array_keys($values)), level: LogLevel::Warning, actor: $actor, context: [
            'keys' => array_keys($values),
            'effective_from' => $effective?->toIso8601String(),
        ]);

        return response()->json(['data' => $this->settings->describe()]);
    }

    public function history(Request $request): JsonResponse
    {
        $request->validate(['key' => ['nullable', 'string', 'max:80']]);

        $page = SettingHistory::query()
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
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
