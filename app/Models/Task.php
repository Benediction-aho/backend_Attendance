<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $work_date
 * @property string $title
 * @property string $status planned|done
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Task extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'completed_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'work_date' => $this->work_date->toDateString(),
            'title' => $this->title,
            'status' => $this->status,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
