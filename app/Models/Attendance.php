<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attendance per user per work date (unique). Times are server times (timestamptz).
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $work_date
 * @property string $day_status
 * @property string $check_in_type
 * @property Carbon $check_in_at
 * @property string|null $check_in_lat
 * @property string|null $check_in_lng
 * @property string|null $check_in_accuracy_m
 * @property string|null $check_in_distance_m
 * @property bool $faceid_verified
 * @property bool $is_late
 * @property string $late_after
 * @property string|null $reason
 * @property string|null $mission_status
 * @property int|null $mission_validated_by
 * @property Carbon|null $mission_validated_at
 * @property string|null $mission_note
 * @property int|null $checked_in_by
 * @property Carbon|null $check_out_at
 * @property string|null $check_out_type
 * @property string|null $check_out_lat
 * @property string|null $check_out_lng
 * @property string|null $check_out_accuracy_m
 * @property string|null $check_out_distance_m
 * @property bool $early_leave
 * @property string|null $early_leave_reason
 * @property string|null $end_of_day
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class Attendance extends Model
{
    public const TYPE_GPS = 'gps';

    public const TYPE_MISSION = 'mission';

    public const TYPE_REMOTE = 'remote';

    public const TYPE_ADMIN = 'admin_on_behalf';

    public const CHECKOUT_MANUAL = 'manual';

    public const CHECKOUT_AUTO = 'auto_midnight';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'mission_validated_at' => 'datetime',
            'faceid_verified' => 'boolean',
            'is_late' => 'boolean',
            'early_leave' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
