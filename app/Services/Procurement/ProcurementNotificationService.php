<?php

namespace App\Services\Procurement;

use App\Models\User;
use App\Models\UserWarehouse;
use App\Notifications\ProcurementWorkflowNotification;

class ProcurementNotificationService
{
    public function send(string $permission, int $warehouseId, string $action, string $message, string $reference, string $url): void
    {
        User::query()->whereNull('deleted_at')->where('statut', 1)->get()->each(function (User $user) use ($permission, $warehouseId, $action, $message, $reference, $url) {
            if (! $user->canProcurement($permission)) {
                return;
            }
            if (! $user->is_all_warehouses && ! UserWarehouse::where('user_id', $user->id)->where('warehouse_id', $warehouseId)->exists()) {
                return;
            }
            if ($user->unreadNotifications()->where('type', ProcurementWorkflowNotification::class)
                ->where('data', 'like', '%"action":"'.$action.'"%')->where('data', 'like', '%"reference":"'.$reference.'"%')->exists()) {
                return;
            }
            $user->notify(new ProcurementWorkflowNotification($action, $message, $reference, $url));
        });
    }
}
