<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $purpose
 * @property string $code_hash
 * @property string|null $lookup_hash
 * @property array<string, mixed>|null $meta
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property string|null $requested_ip
 * @property Carbon|null $created_at
 */
class LoginCode extends Model
{
    public const SUPER_ADMIN_LOGIN = 'super_admin_login';

    public const RECOVERY = 'recovery';

    public const EMAIL_CHANGE_NEW = 'email_change_new';

    public const EMAIL_CHANGE_OLD = 'email_change_old';

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'purpose', 'code_hash', 'lookup_hash', 'meta', 'expires_at', 'used_at', 'requested_ip'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
