<?php

namespace App\Settings;

use App\Models\Setting;
use App\Models\SettingHistory;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Typed settings with effective dates. A value applies from its effective_from onwards; earlier
 * values are never rewritten, so past records keep the rule that applied at their time.
 */
class SettingsService
{
    private const CACHE_KEY = 'settings.versions.v1';

    /** @var array<string, list<array{value: mixed, from: int}>>|null */
    private ?array $memo = null;

    /** Value effective at $at (default: now, server time). */
    public function get(string $key, ?CarbonInterface $at = null): mixed
    {
        $definition = SettingDefinitions::all()[$key] ?? throw new InvalidArgumentException("Unknown setting [$key].");
        $timestamp = ($at ?? Carbon::now())->getTimestamp();

        $value = $definition['default'];
        foreach ($this->versions()[$key] ?? [] as $version) {
            if ($version['from'] <= $timestamp) {
                $value = $version['value'];
            }
        }

        return $this->cast($definition['type'], $value);
    }

    public function int(string $key, ?CarbonInterface $at = null): int
    {
        return (int) $this->get($key, $at);
    }

    public function bool(string $key, ?CarbonInterface $at = null): bool
    {
        return (bool) $this->get($key, $at);
    }

    public function string(string $key, ?CarbonInterface $at = null): string
    {
        return (string) $this->get($key, $at);
    }

    /** @return list<int> */
    public function intList(string $key, ?CarbonInterface $at = null): array
    {
        $value = $this->get($key, $at);

        return is_array($value) ? array_values(array_map('intval', $value)) : [];
    }

    /**
     * Records new values effective from $effectiveFrom (default now). Writes history.
     *
     * @param  array<string, mixed>  $values
     */
    public function set(array $values, ?User $by = null, ?CarbonInterface $effectiveFrom = null): void
    {
        $from = Carbon::instance($effectiveFrom ?? Carbon::now());

        DB::transaction(function () use ($values, $by, $from) {
            foreach ($values as $key => $value) {
                $definition = SettingDefinitions::all()[$key] ?? throw new InvalidArgumentException("Unknown setting [$key].");
                $value = $this->cast($definition['type'], $value);
                $old = $this->get($key, $from);

                Setting::query()->updateOrCreate(
                    ['key' => $key, 'effective_from' => $from],
                    ['value' => $value, 'created_by' => $by?->id],
                );
                SettingHistory::query()->create([
                    'key' => $key,
                    'old_value' => $old,
                    'new_value' => $value,
                    'effective_from' => $from,
                    'changed_by' => $by?->id,
                    'changed_at' => Carbon::now(),
                ]);
            }
        });

        $this->flush();
    }

    /**
     * Current values plus scheduled future changes, for the configuration page.
     *
     * @return list<array{key: string, label: string, group: string, type: string, editable: bool, value: mixed, default: mixed, scheduled: list<array{value: mixed, effective_from: string}>}>
     */
    public function describe(): array
    {
        $now = Carbon::now()->getTimestamp();
        $out = [];
        foreach (SettingDefinitions::all() as $key => $definition) {
            $scheduled = [];
            foreach ($this->versions()[$key] ?? [] as $version) {
                if ($version['from'] > $now) {
                    $scheduled[] = [
                        'value' => $this->cast($definition['type'], $version['value']),
                        'effective_from' => Carbon::createFromTimestamp($version['from'], config('app.timezone'))->toIso8601String(),
                    ];
                }
            }
            $out[] = [
                'key' => $key,
                'label' => $definition['label'],
                'group' => $definition['group'],
                'type' => $definition['type'],
                'editable' => $definition['editable'] ?? true,
                'value' => $this->get($key),
                'default' => $definition['default'],
                'scheduled' => $scheduled,
            ] + self::constraints($key, $definition);
        }

        return $out;
    }

    /**
     * Bounds and unit for the admin form, read from the validation rules (single source of truth).
     *
     * @param  array<string, mixed>  $definition
     * @return array{min: int|float|null, max: int|float|null, unit: string|null, item_min?: int|float|null, item_max?: int|float|null}
     */
    public static function constraints(string $key, array $definition): array
    {
        $bound = function (array $rules, string $name): int|float|null {
            foreach ($rules as $rule) {
                if (is_string($rule) && str_starts_with($rule, $name.':') && is_numeric($v = substr($rule, strlen($name) + 1))) {
                    return $v + 0;
                }
            }

            return null;
        };
        /** @var list<mixed> $rules */
        $rules = (array) ($definition['rules'] ?? []);
        $out = ['min' => $bound($rules, 'min'), 'max' => $bound($rules, 'max'), 'unit' => self::unit($key)];
        if (($definition['type'] ?? null) === 'int_list') {
            /** @var list<mixed> $items */
            $items = (array) ($definition['item_rules'] ?? ['integer', 'min:1', 'max:1440']);
            $out['item_min'] = $bound($items, 'min');
            $out['item_max'] = $bound($items, 'max');
        }

        return $out;
    }

    private static function unit(string $key): ?string
    {
        foreach (['_minutes' => 'minutes', '_hours' => 'hours', '_days' => 'days', '_seconds' => 'seconds', '_meters' => 'meters', '_m' => 'meters', '_per_hour' => 'per hour', '_per_minute' => 'per minute', '_length' => 'characters', '_percent' => 'percent'] as $suffix => $unit) {
            if (str_ends_with($key, $suffix)) {
                return $unit;
            }
        }

        return null;
    }

    public function flush(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, list<array{value: mixed, from: int}>> */
    private function versions(): array
    {
        return $this->memo ??= Cache::remember(self::CACHE_KEY, 300, function () {
            $versions = [];
            foreach (Setting::query()->orderBy('effective_from')->orderBy('id')->get() as $row) {
                $versions[$row->key][] = ['value' => $row->value, 'from' => $row->effective_from->getTimestamp()];
            }

            return $versions;
        });
    }

    private function cast(string $type, mixed $value): mixed
    {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int_list' => is_array($value) ? array_values(array_map('intval', $value)) : [],
            default => (string) $value,
        };
    }
}
