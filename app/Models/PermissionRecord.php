<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $label
 */
class PermissionRecord extends Model
{
    protected $table = 'permissions';

    protected $fillable = ['key', 'label'];
}
