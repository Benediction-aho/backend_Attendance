<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Single-use, short-lived WebAuthn challenge bound to a user, a device and (for check-ins) one intent.
 *
 * @property string $id
 * @property int $user_id
 * @property int|null $device_id
 * @property string $purpose
 * @property string|null $checkin_intent_id
 * @property string $challenge
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property Carbon $created_at
 */
class WebauthnChallenge extends Model
{
    use HasUuids;

    public const REGISTRATION = 'registration';

    public const CHECKIN = 'checkin';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['challenge'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'consumed_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
