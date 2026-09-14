<?php

namespace App\Services\Targets;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;

class TargetAccessService
{
    public function warehouseIds(User $user): Collection
    {
        $all = $user->isSuperAdmin() || (bool) $user->is_all_warehouses
            || $user->effectivePermissionNames()->contains('targets.view_all_warehouses');

        return $all
            ? Warehouse::whereNull('deleted_at')->pluck('id')->map(fn ($id) => (int) $id)
            : $user->assignedWarehouses()->whereNull('warehouses.deleted_at')->pluck('warehouses.id')->map(fn ($id) => (int) $id);
    }
}
