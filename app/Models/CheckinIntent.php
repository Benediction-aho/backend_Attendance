<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property int|null $device_id
 * @property string $type
 * @property Carbon $work_date
 * @property string|null $lat
 * @property string|null $lng
 * @property string|null $accuracy_m
 * @property string|null $distance_m
 * @property string|null $reason
 * @property string|null $challenge_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 */
class CheckinIntent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'expires_at' => 'datetime', 'consumed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
