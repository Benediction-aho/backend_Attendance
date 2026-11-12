<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $identifier_hash
 * @property string|null $name
 * @property string|null $user_agent
 * @property bool $is_pwa
 * @property string|null $last_ip
 * @property Carbon|null $first_login_at
 * @property Carbon|null $last_seen_at
 */
class Device extends Model
{
    protected $fillable = ['user_id', 'identifier_hash', 'name', 'user_agent', 'is_pwa', 'last_ip', 'first_login_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['is_pwa' => 'boolean', 'first_login_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public static function hashIdentifier(string $identifier): string
    {
        return hash('sha256', 'device|'.$identifier);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
