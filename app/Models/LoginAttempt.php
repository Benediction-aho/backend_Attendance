<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $account_key
 * @property int|null $user_id
 * @property string $scope
 * @property string $bucket_key
 * @property int $failures
 * @property int $series
 * @property Carbon|null $blocked_until
 * @property Carbon|null $locked_at
 * @property Carbon|null $last_failed_at
 */
class LoginAttempt extends Model
{
    public const SCOPE_DEVICE = 'device';

    public const SCOPE_UNKNOWN = 'unknown_device';

    /** Current-password checks of a signed-in user (password change, email change). */
    public const SCOPE_REAUTH = 'reauth';

    protected $fillable = ['account_key', 'user_id', 'scope', 'bucket_key', 'failures', 'series', 'blocked_until', 'locked_at', 'last_failed_at'];

    protected function casts(): array
    {
        return ['blocked_until' => 'datetime', 'locked_at' => 'datetime', 'last_failed_at' => 'datetime', 'failures' => 'integer', 'series' => 'integer'];
    }
}
