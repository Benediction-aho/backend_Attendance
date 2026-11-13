<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Attendance\WorkCalendar;
use App\Domain\Reports\ReportBuilder;
use App\Exceptions\ApiError;
use App\Models\Client;
use App\Models\User;
use App\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Clients won (SPECS 7): name, free-text service (suggestions + normalized key), quantity; editable at any time. */
class ClientController
{
    public function __construct(private readonly WorkCalendar $calendar, private readonly ReportBuilder $builder, private readonly SettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $data['date'] ?? $this->calendar->workDate();
        $clients = Client::query()->where('user_id', $this->user($request)->id)->whereDate('work_date', $date)->orderBy('id')->get();

        return response()->json([
            'work_date' => $date,
            'data' => $clients->map(fn (Client $c) => $c->present())->values(),
            'services' => ReportBuilder::services($clients),
        ]);
    }

    /** Suggestions: services already entered (all employees), one spelling per normalized key, most used first. */
    public function suggestions(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $q = Client::serviceKey((string) ($data['q'] ?? ''));
        $rows = DB::table('clients')
            ->selectRaw('service_key, service, count(*) as uses')
            ->when($q !== '', fn ($query) => $query->where('service_key', 'like', '%'.addcslashes($q, '%_\\').'%'))
            ->groupBy('service_key', 'service')
            ->orderByDesc('uses')->limit(500)->get();
        $out = [];
        foreach ($rows as $row) {
            $out[$row->service_key] ??= ['service' => $row->service, 'service_key' => $row->service_key, 'uses' => 0];
            $out[$row->service_key]['uses'] += (int) $row->uses;
        }
        $list = array_values($out);
        usort($list, fn ($a, $b) => [$b['uses'], $a['service_key']] <=> [$a['uses'], $b['service_key']]);

        return response()->json(['data' => array_slice(array_map(fn ($s) => ['service' => $s['service'], 'service_key' => $s['service_key']], $list), 0, 20)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, false);
        $user = $this->user($request);
        $date = $this->calendar->workDate();
        if (Client::query()->where('user_id', $user->id)->whereDate('work_date', $date)->count() >= $this->settings->int('clients_max_per_day')) {
            throw new ApiError(422, 'You have reached the maximum number of clients for today.', 'too_many_clients');
        }
        $client = Client::query()->create(['user_id' => $user->id, 'work_date' => $date] + $data);
        $this->builder->refreshIfGenerated($user, $date);

        return response()->json(['data' => $client->present()], 201);
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $user = $this->user($request);
        if ($client->user_id !== $user->id) {
            throw new ApiError(404, 'Not found.', 'not_found');
        }
        $client->update($this->validated($request, true));
        $this->builder->refreshIfGenerated($user, $client->work_date->toDateString());

        return response()->json(['data' => $client->present()]);
    }

    public function destroy(Request $request, Client $client): JsonResponse
    {
        $user = $this->user($request);
        if ($client->user_id !== $user->id) {
            throw new ApiError(404, 'Not found.', 'not_found');
        }
        $date = $client->work_date->toDateString();
        $client->delete();
        $this->builder->refreshIfGenerated($user, $date);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial): array
    {
        $req = $partial ? ['sometimes', 'required'] : ['required'];
        $max = $this->settings->int('client_quantity_max');
        // UX5-05 / UX5-06: quantity is never coerced (0 → 422) and messages are plain English.
        $quantity = "Quantity must be between 1 and {$max}.";
        $data = $request->validate([
            'name' => [...$req, 'string', 'max:200'],
            'service' => [...$req, 'string', 'max:200'],
            'quantity' => [...$req, 'integer', 'min:1', 'max:'.$max],
        ], [
            'name.required' => 'Please enter the client name.',
            'name.string' => 'Please enter the client name.',
            'name.max' => 'The client name must be 200 characters or fewer.',
            'service.required' => 'Please enter the service.',
            'service.string' => 'Please enter the service.',
            'service.max' => 'The service must be 200 characters or fewer.',
            'quantity.required' => $quantity,
            'quantity.integer' => $quantity,
            'quantity.min' => $quantity,
            'quantity.max' => $quantity,
        ]);
        $out = [];
        if (array_key_exists('name', $data)) {
            $out['name'] = Client::cleanService($data['name']);
            if ($out['name'] === '') {
                throw new ApiError(422, 'Please enter the client name.', 'name_required');
            }
        }
        if (array_key_exists('service', $data)) {
            $out['service'] = Client::cleanService($data['service']);
            $out['service_key'] = Client::serviceKey($data['service']);
            if ($out['service'] === '') {
                throw new ApiError(422, 'Please enter the service.', 'service_required');
            }
        }
        if (array_key_exists('quantity', $data)) {
            $out['quantity'] = (int) $data['quantity'];
        }

        return $out;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
