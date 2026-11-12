<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Face ID passkey: public key only (security.md, Face ID).
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $device_id
 * @property string $credential_id
 * @property string $public_key
 * @property string $user_handle
 * @property string|null $aaguid
 * @property list<string>|null $transports
 * @property string $attestation_type
 * @property int $sign_count
 * @property bool|null $backup_eligible
 * @property bool|null $backup_status
 * @property bool|null $uv_initialized
 * @property string|null $name
 * @property string $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $revoked_by
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_reason
 * @property Carbon|null $last_used_at
 * @property Carbon $created_at
 * @property-read User|null $user
 * @property-read Device|null $device
 */
class WebauthnCredential extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REVOKED = 'revoked';

    protected $guarded = [];

    protected $hidden = ['public_key', 'user_handle'];

    protected function casts(): array
    {
        return [
            'transports' => 'array',
            'sign_count' => 'integer',
            'backup_eligible' => 'boolean',
            'backup_status' => 'boolean',
            'uv_initialized' => 'boolean',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
