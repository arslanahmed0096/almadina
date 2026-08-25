<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $dates = ['deleted_at'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'firstname', 'lastname', 'username', 'email', 'password', 'phone', 'statut', 'avatar', 'role_id', 'is_all_warehouses', 'record_view',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'role_id' => 'integer',
        'statut' => 'integer',
        'is_all_warehouses' => 'integer',
        'record_view' => 'boolean',
    ];

    public function oauthAccessToken()
    {
        return $this->hasMany('\App\Models\OauthAccessToken');
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }

    public function permissionOverrides()
    {
        return $this->belongsToMany(Permission::class)
            ->withPivot('type')
            ->withTimestamps();
    }

    public function allowedPermissionOverrides()
    {
        return $this->permissionOverrides()->wherePivot('type', 'allow');
    }

    public function deniedPermissionOverrides()
    {
        return $this->permissionOverrides()->wherePivot('type', 'deny');
    }

    public function assignRole(Role $role)
    {
        return $this->roles()->save($role);
    }

    public function hasRole($role)
    {
        $this->loadMissing('roles', 'permissionOverrides');

        if (is_string($role)) {
            return $this->roles->contains('name', $role);
        }

        $permissionId = $this->permissionIdFromRoleCollection($role);
        if ($permissionId && $this->hasPermissionOverride($permissionId, 'deny')) {
            return false;
        }

        if ($permissionId && $this->hasPermissionOverride($permissionId, 'allow')) {
            return true;
        }

        return (bool) $role->intersect($this->roles)->count();
    }

    /**
     * Super Admin is the only role allowed to browse inactive catalogue products.
     * Keep the legacy role_id=1 check because older installations may not have the
     * role_user pivot populated for the original administrator account.
     */
    public function isSuperAdmin(): bool
    {
        return (int) $this->role_id === 1 || $this->hasRole('Super Admin');
    }

    public function effectivePermissionNames()
    {
        $this->loadMissing('roles.permissions', 'permissionOverrides');

        $permissionNames = $this->roles
            ->flatMap(function ($role) {
                return $role->permissions->pluck('name');
            });

        $allowed = $this->permissionOverrides
            ->where('pivot.type', 'allow')
            ->pluck('name');

        $denied = $this->permissionOverrides
            ->where('pivot.type', 'deny')
            ->pluck('name');

        return $permissionNames
            ->merge($allowed)
            ->unique()
            ->diff($denied)
            ->values();
    }

    private function permissionIdFromRoleCollection($role)
    {
        if (! $role || ! method_exists($role, 'first')) {
            return null;
        }

        $firstRole = $role->first();
        if (! $firstRole || ! isset($firstRole->pivot) || ! isset($firstRole->pivot->permission_id)) {
            return null;
        }

        return (int) $firstRole->pivot->permission_id;
    }

    private function hasPermissionOverride(int $permissionId, string $type): bool
    {
        return $this->permissionOverrides
            ->contains(function ($permission) use ($permissionId, $type) {
                return (int) $permission->id === $permissionId
                    && isset($permission->pivot)
                    && $permission->pivot->type === $type;
            });
    }

    public function assignedWarehouses()
    {
        return $this->belongsToMany('App\Models\Warehouse');
    }

    public function canProcurement(string $permission): bool
    {
        return $this->isSuperAdmin() || $this->effectivePermissionNames()->contains($permission);
    }

    /**
     * Check if user has record_view permission (user-level boolean with backward compatibility)
     * 
     * @return bool
     */
    public function hasRecordView()
    {
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        if (isset($this->record_view)) {
            return (bool) $this->record_view;
        } else {
            // Fallback to role permission check for backward compatibility
            $role = $this->roles()->first();
            if ($role) {
                return $role->inRole('record_view');
            }
        }
        
        return false;
    }
}
