<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $effective_from
 * @property list<int> $days_off
 * @property list<int> $remote_days
 * @property int|null $set_by
 * @property bool $set_by_employee
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkSchedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'days_off' => 'array', 'remote_days' => 'array', 'set_by_employee' => 'boolean'];
    }
}
