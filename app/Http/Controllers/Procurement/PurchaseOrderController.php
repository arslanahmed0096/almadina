<?php

namespace App\Http\Controllers\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Provider;
use App\Models\PurchaseOrder;
use App\Models\Tax;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\Procurement\ProcurementAuditService;
use App\Services\Procurement\ProcurementNotificationService;
use App\Services\Procurement\PurchaseOrderProgressService;
use App\Services\Procurement\PurchaseOrderService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends Controller
{
    public function __construct(private PurchaseOrderService $service, private PurchaseOrderProgressService $progress, private ProcurementAuditService $audit) {}

    public function index(Request $request)
    {
        $this->permit($request, 'purchase_orders_view');
        $query = PurchaseOrder::with(['provider:id,name', 'warehouse:id,name'])->withSum('items as ordered_quantity', 'ordered_quantity');
        $this->scopeWarehouse($query, $request);
        $query->when($request->search, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('provider', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('items', fn ($i) => $i->where('product_name', 'like', "%{$search}%")->orWhere('variant_name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
            });
        })->when($request->provider_id, fn ($q, $id) => $q->where('provider_id', $id))
            ->when($request->warehouse_id, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->date_from, fn ($q, $date) => $q->whereDate('order_date', '>=', $date))
            ->when($request->date_to, fn ($q, $date) => $q->whereDate('order_date', '<=', $date));
        $page = $query->latest('id')->paginate(min(100, max(1, (int) $request->get('limit', 20))));
        $page->getCollection()->transform(function ($order) {
            $totals = $this->progress->progress($order)['totals'];

            return $order->setAttribute('progress', $totals);
        });

        return response()->json($page);
    }

    public function metadata(Request $request)
    {
        $this->permit($request, 'purchase_orders_view', 'purchase_orders_create');
        $warehouses = Warehouse::whereNull('deleted_at');
        if (! $request->user('api')->is_all_warehouses && ! $request->user('api')->isSuperAdmin()) {
            $warehouses->whereIn('id', UserWarehouse::where('user_id', $request->user('api')->id)->pluck('warehouse_id'));
        }

        return response()->json([
            'providers' => Provider::whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'phone', 'email', 'adresse', 'tax_status', 'strn_number', 'ntn_number']),
            'warehouses' => $warehouses->orderBy('name')->get(['id', 'name']),
            'products' => Product::visibleTo($request->user('api'))->whereNull('deleted_at')->with(['variants:id,product_id,name,code,cost', 'unitPurchase:id,name,ShortName,operator,operator_value'])->orderBy('name')->get(['id', 'name', 'code', 'cost', 'unit_purchase_id', 'is_variant']),
            'taxes' => Tax::effective()->forTransaction('purchase')->orderBy('priority')->get(['id', 'name', 'code', 'rate', 'behavior']),
            'statuses' => collect(PurchaseOrderStatus::cases())->map(fn ($case) => $case->value)->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->permit($request, 'purchase_orders_create');
        $data = $request->validate($this->rules());
        $this->assertWarehouse($request, (int) $data['warehouse_id']);

        return response()->json(['purchase_order' => $this->service->create($data, $request->user('api'))], 201);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->permit($request, 'purchase_orders_view');
        $this->assertWarehouse($request, $purchaseOrder->warehouse_id);
        $purchaseOrder->load(['provider', 'warehouse', 'creator', 'items', 'gatePasses.items', 'supplierInvoices.purchase', 'purchases', 'audits.user']);

        return response()->json(['purchase_order' => $purchaseOrder, 'progress' => $this->progress->progress($purchaseOrder)]);
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->permit($request, 'purchase_orders_edit_draft');
        $this->assertWarehouse($request, $purchaseOrder->warehouse_id);

        return response()->json(['purchase_order' => $this->service->update($purchaseOrder, $request->validate($this->rules()))]);
    }

    public function issue(Request $request, PurchaseOrder $purchaseOrder, ProcurementNotificationService $notifications)
    {
        $this->permit($request, 'purchase_orders_issue');
        $this->assertWarehouse($request, $purchaseOrder->warehouse_id);
        $order = $this->service->issue($purchaseOrder, $request->user('api'));
        $notifications->send('purchase_orders_view', $order->warehouse_id, 'purchase_order_issued', "Purchase Order {$order->number} was issued.", $order->number, '/app/procurement/purchase-orders/'.$order->id);

        return response()->json(['purchase_order' => $order]);
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->permit($request, 'purchase_orders_cancel');
        $this->assertWarehouse($request, $purchaseOrder->warehouse_id);
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        DB::transaction(function () use ($purchaseOrder, $request, $data) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);
            if ($order->gatePasses()->whereIn('status', ['accepted', 'partially_accepted'])->exists()) {
                throw ValidationException::withMessages(['status' => ['A Purchase Order with received stock cannot be cancelled without a controlled stock reversal.']]);
            }
            $old = ['status' => $order->status];
            $order->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $request->user('api')->id, 'cancellation_reason' => $data['reason']]);
            $this->audit->record($order, 'cancelled', $old, ['status' => 'cancelled'], $data['reason']);
        });

        return response()->json(['success' => true]);
    }

    public function pdf(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->permit($request, 'purchase_orders_pdf', 'purchase_orders_view');
        $this->assertWarehouse($request, $purchaseOrder->warehouse_id);
        $purchaseOrder->load(['provider', 'warehouse', 'items']);

        return Pdf::loadView('pdf.purchase_order', ['order' => $purchaseOrder, 'setting' => \App\Models\Setting::whereNull('deleted_at')->first()])
            ->setPaper('a4')->download($purchaseOrder->number.'.pdf');
    }

    private function rules(): array
    {
        return [
            'order_date' => 'required|date', 'expected_delivery_date' => 'nullable|date|after_or_equal:order_date',
            'provider_id' => 'required|integer|exists:providers,id', 'warehouse_id' => 'required|integer|exists:warehouses,id',
            'notes' => 'nullable|string|max:5000', 'terms' => 'nullable|string|max:10000', 'reason' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer|exists:product_variants,id', 'items.*.unit_id' => 'nullable|integer|exists:units,id',
            'items.*.quantity' => 'required|numeric|gt:0|decimal:0,6', 'items.*.unit_price' => 'nullable|numeric|min:0|decimal:0,6',
            'items.*.discount' => 'nullable|numeric|min:0|decimal:0,6', 'items.*.discount_method' => ['nullable', Rule::in(['fixed', 'percentage'])],
            'items.*.tax_id' => 'nullable|integer|exists:taxes,id', 'items.*.notes' => 'nullable|string|max:1000',
        ];
    }

    private function permit(Request $request, string ...$permissions): void
    {
        if (! collect($permissions)->contains(fn ($permission) => $request->user('api')->canProcurement($permission))) {
            abort(403);
        }
    }

    private function assertWarehouse(Request $request, int $warehouseId): void
    {
        $user = $request->user('api');
        if ($user->isSuperAdmin() || $user->is_all_warehouses) {
            return;
        }
        abort_unless(UserWarehouse::where('user_id', $user->id)->where('warehouse_id', $warehouseId)->exists(), 403);
    }

    private function scopeWarehouse($query, Request $request): void
    {
        $user = $request->user('api');
        if ($user->isSuperAdmin() || $user->is_all_warehouses) {
            return;
        }
        $query->whereIn('warehouse_id', UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id'));
    }
}
