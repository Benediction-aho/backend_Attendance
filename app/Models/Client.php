<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A client won by an employee on a work date (SPECS 7). `service` is free text as typed;
 * `service_key` is its normalized form used for grouping in reports.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $work_date
 * @property string $name
 * @property string $service
 * @property string $service_key
 * @property int $quantity
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Client extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'quantity' => 'integer'];
    }

    /** Collapses whitespace (display form). */
    public static function cleanService(string $service): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $service));
    }

    /** Grouping key: case and extra spaces ignored ("Residence  permit " = "residence permit"). */
    public static function serviceKey(string $service): string
    {
        return mb_strtolower(self::cleanService($service));
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date->toDateString(),
            'name' => $this->name,
            'service' => $this->service,
            'service_key' => $this->service_key,
            'quantity' => $this->quantity,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
