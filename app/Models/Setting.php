<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One version of a setting value, effective from effective_from.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property Carbon $effective_from
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class Setting extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['key', 'value', 'effective_from', 'created_by'];

    protected function casts(): array
    {
        return ['value' => 'json', 'effective_from' => 'datetime'];
    }
}
