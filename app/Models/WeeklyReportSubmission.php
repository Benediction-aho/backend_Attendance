<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One uploaded version of an employee's weekly PDF report. Replacing it adds a version; the
 * previous file is kept (is_current = false).
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $week_start
 * @property Carbon $window_date
 * @property int $version
 * @property bool $is_current
 * @property string $path
 * @property string $original_name
 * @property int $size_bytes
 * @property string $sha256
 * @property Carbon $submitted_at
 * @property bool $is_late
 * @property Carbon|null $forwarded_at
 * @property-read User $user
 */
class WeeklyReportSubmission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date', 'window_date' => 'date', 'is_current' => 'boolean', 'is_late' => 'boolean',
            'submitted_at' => 'datetime', 'forwarded_at' => 'datetime', 'size_bytes' => 'integer', 'version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
