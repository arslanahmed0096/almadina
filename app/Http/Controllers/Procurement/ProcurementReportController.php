<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\UserWarehouse;
use App\Services\Procurement\PurchaseOrderProgressService;
use Illuminate\Http\Request;

class ProcurementReportController extends Controller
{
    public function __construct(private PurchaseOrderProgressService $progress) {}

    public function summary(Request $request)
    {
        abort_unless($request->user('api')->canProcurement('procurement_reports_view'), 403);
        $q = PurchaseOrder::with(['provider:id,name', 'warehouse:id,name']);
        $u = $request->user('api');
        if (! $u->isSuperAdmin() && ! $u->is_all_warehouses) {
            $q->whereIn('warehouse_id', UserWarehouse::where('user_id', $u->id)->pluck('warehouse_id'));
        }
        $q->when($request->provider_id, fn ($q, $v) => $q->where('provider_id', $v))->when($request->warehouse_id, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))->when($request->date_from, fn ($q, $v) => $q->whereDate('order_date', '>=', $v))->when($request->date_to, fn ($q, $v) => $q->whereDate('order_date', '<=', $v));
        $rows = $q->latest()->get()->map(function ($order) {
            $p = $this->progress->progress($order);

            return ['purchase_order' => $order, 'progress' => $p['totals'], 'lines' => $p['lines']];
        });

        return response()->json(['rows' => $rows, 'goods_received_not_invoiced' => $rows->sum(fn ($r) => $r['progress']['not_invoiced']), 'invoiced_not_posted' => $rows->sum(fn ($r) => $r['progress']['invoiced'] - $r['progress']['purchased'])]);
    }
}
