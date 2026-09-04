<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\GatePass;
use App\Models\Provider;
use App\Models\PurchaseOrder;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\Procurement\GatePassService;
use App\Services\Procurement\ProcurementAuditService;
use App\Services\Procurement\PurchaseOrderProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class GatePassController extends Controller
{
    public function __construct(
        private GatePassService $service,
        private ProcurementAuditService $audit,
        private PurchaseOrderProgressService $progress
    ) {}

    public function index(Request $request)
    {
        $this->permit($request, 'gate_passes_view');
        $q = GatePass::with(['purchaseOrder:id,number', 'provider:id,name', 'warehouse:id,name'])->withSum('items as delivered_quantity', 'delivered_quantity')->withSum('items as accepted_quantity', 'accepted_quantity');
        $this->scope($q, $request);
        $q->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('number', 'like', "%{$s}%")->orWhere('supplier_gate_pass_number', 'like', "%{$s}%")->orWhere('bilty_number', 'like', "%{$s}%")->orWhere('vehicle_number', 'like', "%{$s}%")->orWhereHas('purchaseOrder', fn ($p) => $p->where('number', 'like', "%{$s}%"))))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v));

        $page = $q->latest('id')->paginate(min(100, max(1, (int) $request->get('limit', 20))));
        $purchaseOrderIds = $page->getCollection()->pluck('purchase_order_id')->filter()->unique()->values();
        $remainingByOrder = collect();
        if ($purchaseOrderIds->isNotEmpty()) {
            $orderItems = DB::table('purchase_order_items')
                ->whereIn('purchase_order_id', $purchaseOrderIds)
                ->get(['id', 'purchase_order_id', 'ordered_quantity']);
            $gatePassReceived = DB::table('gate_pass_items as items')
                ->join('gate_passes as gates', 'gates.id', '=', 'items.gate_pass_id')
                ->whereIn('gates.purchase_order_id', $purchaseOrderIds)
                ->whereIn('gates.status', ['accepted', 'partially_accepted'])
                ->selectRaw('items.purchase_order_item_id, SUM(items.accepted_quantity) AS quantity')
                ->groupBy('items.purchase_order_item_id')
                ->pluck('quantity', 'items.purchase_order_item_id');
            $invoiceReceived = DB::table('purchase_details as details')
                ->join('purchases', 'purchases.id', '=', 'details.purchase_id')
                ->whereIn('purchases.purchase_order_id', $purchaseOrderIds)
                ->whereNull('purchases.deleted_at')
                ->where('purchases.posting_status', '<>', 'cancelled')
                ->selectRaw('details.purchase_order_item_id, SUM(details.invoice_excess_quantity) AS quantity')
                ->groupBy('details.purchase_order_item_id')
                ->pluck('quantity', 'details.purchase_order_item_id');

            $remainingByOrder = $orderItems->groupBy('purchase_order_id')->map(fn ($items) => (float) $items->sum(fn ($item) => max(
                0,
                (float) $item->ordered_quantity
                    - (float) ($gatePassReceived[$item->id] ?? 0)
                    - (float) ($invoiceReceived[$item->id] ?? 0)
            )));
        }
        $page->getCollection()->transform(function ($gatePass) use ($remainingByOrder) {
            $gatePass->setAttribute(
                'po_remaining_quantity',
                $gatePass->purchase_order_id ? (float) ($remainingByOrder[$gatePass->purchase_order_id] ?? 0) : null
            );

            return $gatePass;
        });

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $this->permit($request, 'gate_passes_create');
        if (is_string($request->items)) {
            $request->merge(['items' => json_decode($request->items, true)]);
        }
        $data = $request->validate([
            'receipt_type' => ['required', Rule::in(['purchase_order', 'direct'])],
            'purchase_order_id' => 'nullable|required_if:receipt_type,purchase_order|integer|exists:purchase_orders,id',
            'provider_id' => 'nullable|required_if:receipt_type,direct|integer|exists:providers,id',
            'warehouse_id' => 'nullable|required_if:receipt_type,direct|integer|exists:warehouses,id',
            'supplier_gate_pass_number' => 'nullable|string|max:100',
            'delivered_at' => 'required|date', 'bilty_number' => 'nullable|string|max:100', 'vehicle_number' => 'nullable|string|max:80',
            'driver_name' => 'nullable|string|max:150', 'driver_phone' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:5000',
            'submit_for_verification' => 'nullable|boolean', 'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'items' => 'required|array|min:1', 'items.*.purchase_order_item_id' => 'nullable|required_if:receipt_type,purchase_order|integer',
            'items.*.product_id' => 'nullable|required_if:receipt_type,direct|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.unit_id' => 'nullable|integer|exists:units,id', 'items.*.unit_cost' => 'nullable|numeric|min:0|decimal:0,6',
            'items.*.delivered_quantity' => 'required|integer|gt:0', 'items.*.accepted_quantity' => 'required|integer|min:0',
            'items.*.rejected_quantity' => 'nullable|numeric|min:0|decimal:0,6', 'items.*.short_quantity' => 'nullable|numeric|min:0|decimal:0,6',
            'items.*.over_delivery_reason' => 'nullable|string|max:2000', 'items.*.notes' => 'nullable|string|max:1000',
        ]);
        $order = ! empty($data['purchase_order_id']) ? PurchaseOrder::findOrFail($data['purchase_order_id']) : null;
        $gatePass = $this->service->create($order, $data, $request->user('api'));
        if ($request->hasFile('attachment')) {
            $this->storeAttachment($request, $gatePass);
            $this->audit->record($gatePass, 'attachment_uploaded', [], ['attachment_name' => $gatePass->attachment_name, 'attachment_mime' => $gatePass->attachment_mime], null, $gatePass->purchase_order_id);
        }

        return response()->json(['gate_pass' => $gatePass->fresh(['items.purchaseOrderItem', 'purchaseOrder', 'provider', 'warehouse'])], 201);
    }

    public function metadata(Request $request)
    {
        $this->permit($request, 'gate_passes_create');
        $warehouses = Warehouse::whereNull('deleted_at');
        $purchaseOrders = PurchaseOrder::with(['provider:id,name', 'warehouse:id,name'])
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereHas('items', function ($items) {
                $items->whereRaw("purchase_order_items.ordered_quantity > COALESCE((SELECT SUM(gate_pass_items.accepted_quantity) FROM gate_pass_items INNER JOIN gate_passes ON gate_passes.id = gate_pass_items.gate_pass_id WHERE gate_pass_items.purchase_order_item_id = purchase_order_items.id AND gate_passes.status IN ('accepted', 'partially_accepted')), 0)");
            });
        if (! $request->user('api')->is_all_warehouses && ! $request->user('api')->isSuperAdmin()) {
            $warehouseIds = UserWarehouse::where('user_id', $request->user('api')->id)->pluck('warehouse_id');
            $warehouses->whereIn('id', $warehouseIds);
            $purchaseOrders->whereIn('warehouse_id', $warehouseIds);
        }

        $selectedOrder = null;
        $selectedProgress = null;
        if ($request->filled('purchase_order_id')) {
            $selectedOrder = (clone $purchaseOrders)->with(['provider', 'warehouse', 'items'])->findOrFail($request->integer('purchase_order_id'));
            $selectedProgress = $this->progress->progress($selectedOrder);
        }

        return response()->json([
            'providers' => Provider::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'warehouses' => $warehouses->orderBy('name')->get(['id', 'name']),
            'purchase_orders' => $purchaseOrders->latest('id')->get(['id', 'number', 'provider_id', 'warehouse_id', 'status']),
            'purchase_order' => $selectedOrder,
            'progress' => $selectedProgress,
        ]);
    }

    public function show(Request $request, GatePass $gatePass)
    {
        $this->permit($request, 'gate_passes_view');
        $this->assertWarehouse($request, $gatePass->warehouse_id);

        return response()->json(['gate_pass' => $gatePass->load(['purchaseOrder', 'provider', 'warehouse', 'receiver', 'items.purchaseOrderItem', 'supplierInvoices.purchase'])]);
    }

    public function confirm(Request $request, GatePass $gatePass)
    {
        $this->permit($request, 'gate_passes_confirm');

        return response()->json(['gate_pass' => $this->service->confirm($gatePass, $request->user('api'))]);
    }

    public function reject(Request $request, GatePass $gatePass)
    {
        $this->permit($request, 'gate_passes_reject');
        $data = $request->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['gate_pass' => $this->service->reject($gatePass, $request->user('api'), $data['reason'])]);
    }

    public function cancel(Request $request, GatePass $gatePass)
    {
        $this->permit($request, 'gate_passes_reject');
        $data = $request->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['gate_pass' => $this->service->cancel($gatePass, $request->user('api'), $data['reason'])]);
    }

    public function replaceAttachment(Request $request, GatePass $gatePass)
    {
        $this->permit($request, 'gate_passes_upload');
        $this->assertWarehouse($request, $gatePass->warehouse_id);
        $request->validate(['attachment' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240', 'reason' => 'required|string|max:1000']);
        $old = ['attachment_name' => $gatePass->attachment_name, 'attachment_path' => $gatePass->attachment_path];
        $this->storeAttachment($request, $gatePass);
        $this->audit->record($gatePass, 'attachment_replaced', $old, ['attachment_name' => $gatePass->attachment_name], $request->reason, $gatePass->purchase_order_id);

        return response()->json(['gate_pass' => $gatePass]);
    }

    public function downloadAttachment(Request $request, GatePass $gatePass)
    {
        $this->permit($request, 'gate_passes_view');
        $this->assertWarehouse($request, $gatePass->warehouse_id);
        abort_unless($gatePass->attachment_path && Storage::disk('local')->exists($gatePass->attachment_path), 404);

        return Storage::disk('local')->download($gatePass->attachment_path, $gatePass->attachment_name);
    }

    private function storeAttachment(Request $request, GatePass $gatePass): void
    {
        if ($gatePass->attachment_path) {
            Storage::disk('local')->delete($gatePass->attachment_path);
        }
        $file = $request->file('attachment');
        $path = $file->store('procurement/gate-passes', 'local');
        $gatePass->update(['attachment_path' => $path, 'attachment_name' => $file->getClientOriginalName(), 'attachment_mime' => $file->getMimeType()]);
    }

    private function permit(Request $request, string $p): void
    {
        abort_unless($request->user('api')->canProcurement($p), 403);
    }

    private function assertWarehouse(Request $request, int $id): void
    {
        $u = $request->user('api');
        if ($u->isSuperAdmin() || $u->is_all_warehouses) {
            return;
        } abort_unless(UserWarehouse::where('user_id', $u->id)->where('warehouse_id', $id)->exists(), 403);
    }

    private function scope($q, Request $request): void
    {
        $u = $request->user('api');
        if (! $u->isSuperAdmin() && ! $u->is_all_warehouses) {
            $q->whereIn('warehouse_id', UserWarehouse::where('user_id', $u->id)->pluck('warehouse_id'));
        }
    }
}
