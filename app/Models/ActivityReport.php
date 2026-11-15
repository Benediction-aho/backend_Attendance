<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A PDF report about an activity, sent at any time (not the weekly Friday report).
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $work_date
 * @property string $title
 * @property string|null $note
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property Carbon $submitted_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 */
class ActivityReport extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'submitted_at' => 'datetime', 'size_bytes' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
