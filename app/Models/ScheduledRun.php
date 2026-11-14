<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $task
 * @property string $run_key
 * @property string $status
 * @property Carbon|null $scheduled_for
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error
 */
class ScheduledRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['task', 'run_key', 'status', 'scheduled_for', 'started_at', 'finished_at', 'error'];

    protected function casts(): array
    {
        return ['scheduled_for' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
