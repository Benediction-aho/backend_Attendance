<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A refused check-in (out of perimeter, low accuracy).
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $work_date
 * @property string $outcome
 * @property string|null $distance_m
 * @property string|null $accuracy_m
 * @property Carbon $created_at
 * @property-read User|null $user
 */
class CheckinAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
