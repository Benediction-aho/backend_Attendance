<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $month
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $path
 * @property string $file_name
 * @property Carbon $generated_at
 */
class MonthlyReport extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'generated_at' => 'datetime'];
    }
}
