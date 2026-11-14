<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Snapshot of an employee's day generated at daily_report_time and recomputed on later edits.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $work_date
 * @property int $tasks_planned
 * @property int $tasks_done
 * @property int $clients_count
 * @property list<array{service: string, service_key: string, quantity: int}> $services
 * @property Carbon $generated_at
 * @property Carbon|null $recomputed_at
 */
class DailyReport extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'services' => 'array', 'generated_at' => 'datetime', 'recomputed_at' => 'datetime'];
    }
}
