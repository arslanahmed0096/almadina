<?php

namespace App\Http\Controllers;

use App\Models\Provider;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\Reports\DailyReportService;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;

class DailyReportController extends Controller
{
    public function __invoke(Request $request, DailyReportService $service)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'provider_id' => ['nullable', 'integer', 'exists:providers,id'],
        ]);
        $user = $request->user('api');
        abort_unless($user && ($user->isSuperAdmin() || $user->effectivePermissionNames()->contains('daily_reports_view')), 403);

        $warehouseQuery = Warehouse::query()->whereNull('deleted_at')->orderBy('name');
        if (! $user->is_all_warehouses) {
            $allowedIds = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id');
            $warehouseQuery->whereIn('id', $allowedIds);
        }
        $warehouses = $warehouseQuery->get(['id', 'name']);
        $selectedWarehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
        if ($selectedWarehouseId) {
            abort_unless($warehouses->contains('id', $selectedWarehouseId), 403);
            $reportWarehouses = $warehouses->where('id', $selectedWarehouseId)->values();
        } else {
            $reportWarehouses = $warehouses;
        }

        $date = Carbon::createFromFormat('Y-m-d', $data['date'] ?? now()->toDateString())->startOfDay();
        $includeGlobalBalances = (bool) $user->is_all_warehouses && ! $selectedWarehouseId;
        $selectedProviderId = isset($data['provider_id']) ? (int) $data['provider_id'] : null;

        return response()->json([
            'report' => $service->build($date, $reportWarehouses, $includeGlobalBalances, $selectedProviderId),
            'warehouses' => $warehouses,
            'suppliers' => Provider::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'currency' => (new helpers)->Get_Currency_Code(),
            'can_export' => $user->isSuperAdmin() || $user->effectivePermissionNames()->contains('daily_reports_export'),
        ]);
    }
}
