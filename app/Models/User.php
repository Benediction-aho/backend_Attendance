<?php

namespace App\Models;

use App\Enums\EmployeeType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property Role $role
 * @property EmployeeType $employee_type
 * @property string|null $job_title
 * @property UserStatus $status
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property Carbon|null $birth_date
 * @property string|null $address
 * @property string|null $photo_path
 * @property string|null $password
 * @property bool $must_change_password
 * @property Carbon|null $initial_code_expires_at
 * @property Carbon|null $password_changed_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'first_name', 'last_name', 'email', 'role', 'employee_type', 'job_title', 'status',
        'phone', 'whatsapp', 'birth_date', 'address',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** @var array<string, bool>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'employee_type' => EmployeeType::class,
            'status' => UserStatus::class,
            'birth_date' => 'date',
            'must_change_password' => 'boolean',
            'initial_code_expires_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'approved_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function setEmailAttribute(string $value): void
    {
        $this->attributes['email'] = self::normalizeEmail($value);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public static function findByEmail(string $email): ?self
    {
        return self::query()->whereRaw('lower(email) = ?', [self::normalizeEmail($email)])->first();
    }

    /** @return BelongsToMany<PermissionRecord, $this> */
    public function permissionRecords(): BelongsToMany
    {
        return $this->belongsToMany(PermissionRecord::class, 'user_permissions', 'user_id', 'permission_id')
            ->withPivot('granted_by', 'created_at');
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isStaff(): bool
    {
        return $this->isSuperAdmin() || $this->isAdmin();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active && $this->deleted_at === null;
    }

    /** Super admins hold every permission; admins only the ones granted; employees none. */
    public function hasPermission(Permission $permission): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }
        if (! $this->isAdmin()) {
            return false;
        }

        return $this->grantedPermissions()[$permission->value] ?? false;
    }

    /** @return array<string, bool> */
    public function grantedPermissions(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = $this->permissionRecords()->pluck('key')->mapWithKeys(fn ($k) => [(string) $k => true])->all();
        }

        return $this->permissionCache;
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        if ($this->isSuperAdmin()) {
            return array_map(fn (Permission $p) => $p->value, Permission::cases());
        }
        if (! $this->isAdmin()) {
            return [];
        }

        return array_keys($this->grantedPermissions());
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    /**
     * Active users holding a permission (super admins included).
     *
     * @param  Builder<User>  $query
     */
    public function scopeHoldingPermission(Builder $query, Permission $permission): void
    {
        $query->where('status', UserStatus::Active->value)
            ->where(function (Builder $q) use ($permission) {
                $q->where('role', Role::SuperAdmin->value)
                    ->orWhere(function (Builder $q) use ($permission) {
                        $q->where('role', Role::Admin->value)
                            ->whereHas('permissionRecords', fn (Builder $p) => $p->where('key', $permission->value));
                    });
            });
    }

    /** @param  Builder<User>  $query */
    public function scopeActiveSuperAdmins(Builder $query): void
    {
        $query->where('role', Role::SuperAdmin->value)->where('status', UserStatus::Active->value);
    }
}
