<?php

namespace App\Models;

use App\Enums\LogLevel;
use App\Enums\LogType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only (also enforced by a database trigger).
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $actor_id
 * @property-read Device|null $device
 * @property LogType $type
 * @property LogLevel $level
 * @property string $event
 * @property string $description
 * @property array<string, mixed>|null $context
 * @property string|null $ip
 * @property int|null $device_id
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'actor_id', 'type', 'level', 'event', 'description', 'context', 'ip', 'device_id', 'user_agent'];

    protected function casts(): array
    {
        return ['type' => LogType::class, 'level' => LogLevel::class, 'context' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Activity logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Activity logs are append-only.'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
