<?php

namespace App\Models;

use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'password', 'phone', 'is_active', 'role_template_id', 'hourly_cost', 'capacity_hours', 'work_days'];

    protected $hidden = ['password', 'remember_token', 'hourly_cost'];

    /** @var array<string, string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'is_guest' => 'boolean',
            'hourly_cost' => 'decimal:2',
            'capacity_hours' => 'float',
            'work_days' => 'array',
        ];
    }

    /** Access to clients, projects or lists shared with this person (see Services\Tasks\Access). */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function roleTemplate(): BelongsTo
    {
        return $this->belongsTo(RoleTemplate::class);
    }

    /**
     * @return array<string, string> permission => scope
     */
    public function permissionMap(): array
    {
        if ($this->permissionCache === null) {
            $this->permissionCache = $this->grants()->pluck('scope', 'permission')->all();
        }

        return $this->permissionCache;
    }

    public function hasPermission(string $permission, ?string $minScope = null): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        $scope = $this->permissionMap()[$permission] ?? null;

        if ($scope === null) {
            return false;
        }

        return $minScope === null || Permissions::scopeCovers($scope, $minScope);
    }

    /** Working days (0 = Sunday … 6 = Saturday). @return list<int> */
    public function workDays(): array
    {
        return $this->work_days ?: config('tasks.work_days');
    }

    public function permissionScope(string $permission): ?string
    {
        return $this->is_super_admin ? 'all' : ($this->permissionMap()[$permission] ?? null);
    }

    /**
     * Replace all grants with the given map.
     *
     * @param  array<string, string>  $map
     */
    public function syncPermissions(array $map): void
    {
        $map = Permissions::sanitize($map);
        $this->grants()->delete();
        foreach ($map as $permission => $scope) {
            $this->grants()->create(['permission' => $permission, 'scope' => $scope]);
        }
        $this->permissionCache = null;
    }
}
