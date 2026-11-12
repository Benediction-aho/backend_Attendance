<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property mixed $old_value
 * @property mixed $new_value
 * @property Carbon $effective_from
 * @property int|null $changed_by
 * @property Carbon $changed_at
 */
class SettingHistory extends Model
{
    protected $table = 'setting_history';

    public $timestamps = false;

    protected $fillable = ['key', 'old_value', 'new_value', 'effective_from', 'changed_by', 'changed_at'];

    protected function casts(): array
    {
        return ['old_value' => 'json', 'new_value' => 'json', 'effective_from' => 'datetime', 'changed_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by')->withTrashed();
    }
}
