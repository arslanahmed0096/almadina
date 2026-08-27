<?php

namespace App\Http\Controllers\Procurement;

use App\Http\Controllers\Controller;
use App\Models\GatePass;
use App\Models\SupplierInvoice;
use App\Models\Tax;
use App\Models\UserWarehouse;
use App\Services\Procurement\ProcurementAuditService;
use App\Services\Procurement\SupplierInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SupplierInvoiceController extends Controller
{
    public function __construct(private SupplierInvoiceService $service, private ProcurementAuditService $audit) {}

    public function index(Request $request)
    {
        $this->permit($request, 'supplier_invoices_view');
        $q = SupplierInvoice::with(['provider:id,name', 'purchaseOrder:id,number', 'gatePass:id,number,supplier_gate_pass_number,warehouse_id', 'purchase:id,supplier_invoice_id,Ref,payment_statut,posting_status']);
        if (! $request->user('api')->is_all_warehouses && ! $request->user('api')->isSuperAdmin()) {
            $ids = UserWarehouse::where('user_id', $request->user('api')->id)->pluck('warehouse_id');
            $q->whereHas('gatePass', fn ($g) => $g->whereIn('warehouse_id', $ids));
        }
        $q->when($request->search, fn ($q, $s) => $q->where(fn ($q) => $q->where('supplier_invoice_number', 'like', "%{$s}%")->orWhere('number', 'like', "%{$s}%")->orWhereHas('provider', fn ($p) => $p->where('name', 'like', "%{$s}%"))->orWhereHas('purchaseOrder', fn ($p) => $p->where('number', 'like', "%{$s}%"))))
            ->when($request->tax_type, fn ($q, $v) => $q->where('tax_type', $v))->when($request->status, fn ($q, $v) => $q->where('status', $v));

        return response()->json($q->latest('id')->paginate(min(100, max(1, (int) $request->get('limit', 20)))));
    }

    public function metadata(Request $request, GatePass $gatePass)
    {
        $this->permit($request, 'supplier_invoices_create');
        $this->assertWarehouse($request, $gatePass->warehouse_id);
        $gatePass->load(['provider', 'purchaseOrder', 'items.purchaseOrderItem']);
        $invoiced = \DB::table('supplier_invoice_items as i')->join('supplier_invoices as s', 's.id', '=', 'i.supplier_invoice_id')
            ->where('s.status', '<>', 'cancelled')->whereIn('i.gate_pass_item_id', $gatePass->items->pluck('id'))
            ->selectRaw('i.gate_pass_item_id, SUM(i.quantity) as quantity')->groupBy('i.gate_pass_item_id')->get()->pluck('quantity', 'gate_pass_item_id');
        $purchased = \DB::table('purchase_gate_pass_items as i')->join('purchases as p', 'p.id', '=', 'i.purchase_id')
            ->whereNull('p.deleted_at')->where('p.posting_status', '<>', 'cancelled')->whereIn('i.gate_pass_item_id', $gatePass->items->pluck('id'))
            ->selectRaw('i.gate_pass_item_id, SUM(i.quantity) as quantity')->groupBy('i.gate_pass_item_id')->get()->pluck('quantity', 'gate_pass_item_id');

        return response()->json([
            'gate_pass' => $gatePass,
            'items' => $gatePass->items->map(fn ($item) => [
                'gate_pass_item_id' => $item->id, 'product' => $item->product_name ?: $item->purchaseOrderItem?->product_name,
                'model' => $item->variant_name ?: $item->purchaseOrderItem?->variant_name,
                'sku' => $item->sku ?: $item->purchaseOrderItem?->sku,
                'accepted_quantity' => (float) $item->accepted_quantity,
                'previously_invoiced' => (float) ($invoiced[$item->id] ?? 0) + (float) ($purchased[$item->id] ?? 0),
                'remaining_quantity' => max(0, (float) $item->accepted_quantity - (float) ($invoiced[$item->id] ?? 0) - (float) ($purchased[$item->id] ?? 0)),
                'default_cost' => (float) ($item->default_unit_cost ?: $item->purchaseOrderItem?->unit_price ?: 0),
            ]),
            'default_tax_type' => $gatePass->provider->tax_status ?: 'non_gst',
            'taxes' => Tax::effective()->forTransaction('purchase')->forWarehouse($gatePass->warehouse_id)->orderBy('priority')->get(['id', 'name', 'code', 'rate', 'behavior']),
        ]);
    }

    public function store(Request $request)
    {
        $this->permit($request, 'supplier_invoices_create');
        if (is_string($request->items)) {
            $request->merge(['items' => json_decode($request->items, true)]);
        }
        $data = $request->validate([
            'gate_pass_id' => 'required|integer|exists:gate_passes,id', 'supplier_invoice_number' => 'required|string|max:120',
            'invoice_date' => 'required|date', 'due_date' => 'nullable|date|after_or_equal:invoice_date', 'tax_type' => ['nullable', Rule::in(['gst', 'non_gst'])],
            'supplier_strn_number' => 'nullable|string|max:80', 'supplier_ntn_number' => 'nullable|string|max:80', 'tax_override_reason' => 'nullable|string|max:2000',
            'other_charges' => 'nullable|numeric|min:0|decimal:0,6', 'freight_charges' => 'nullable|numeric|min:0|decimal:0,6',
            'notes' => 'nullable|string|max:5000', 'save_as_draft' => 'nullable|boolean', 'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'items' => 'required|array|min:1', 'items.*.gate_pass_item_id' => 'required|integer', 'items.*.quantity' => 'required|numeric|gt:0|decimal:0,6',
            'items.*.unit_cost' => 'required|numeric|min:0|decimal:0,6', 'items.*.discount' => 'nullable|numeric|min:0|decimal:0,6',
            'items.*.discount_method' => ['nullable', Rule::in(['fixed', 'percentage'])], 'items.*.tax_id' => 'nullable|integer|exists:taxes,id',
        ]);
        $gatePass = GatePass::findOrFail($data['gate_pass_id']);
        $invoice = $this->service->create($gatePass, $data, $request->user('api'));
        if ($request->hasFile('attachment')) {
            $this->storeAttachment($request, $invoice);
            $this->audit->record($invoice, 'attachment_uploaded', [], ['attachment_name' => $invoice->attachment_name, 'attachment_mime' => $invoice->attachment_mime], null, $invoice->purchase_order_id);
        }

        return response()->json(['supplier_invoice' => $invoice->fresh(['items', 'provider', 'gatePass', 'purchaseOrder'])], 201);
    }

    public function show(Request $request, SupplierInvoice $supplierInvoice)
    {
        $this->permit($request, 'supplier_invoices_view');
        $this->assertWarehouse($request, $supplierInvoice->gatePass()->value('warehouse_id'));

        return response()->json(['supplier_invoice' => $supplierInvoice->load(['provider', 'purchaseOrder', 'gatePass', 'items.gatePassItem.purchaseOrderItem', 'purchase.details'])]);
    }

    public function record(Request $request, SupplierInvoice $supplierInvoice)
    {
        $this->permit($request, 'supplier_invoices_post');

        return response()->json(['supplier_invoice' => $this->service->record($supplierInvoice, $request->user('api'))]);
    }

    public function createPurchase(Request $request, SupplierInvoice $supplierInvoice)
    {
        $this->permit($request, 'purchases_from_supplier_invoice');
        $this->permit($request, 'purchases_post');

        return response()->json(['purchase' => $this->service->createPurchase($supplierInvoice, $request->user('api'))], 201);
    }

    public function cancel(Request $request, SupplierInvoice $supplierInvoice)
    {
        $this->permit($request, 'supplier_invoices_post');
        $data = $request->validate(['reason' => 'required|string|max:2000']);

        return response()->json(['supplier_invoice' => $this->service->cancel($supplierInvoice, $request->user('api'), $data['reason'])]);
    }

    public function replaceAttachment(Request $request, SupplierInvoice $supplierInvoice)
    {
        $this->permit($request, 'supplier_invoices_edit_draft');
        $request->validate(['attachment' => 'required|file|mimes:jpg,jpeg,png,pdf|max:10240', 'reason' => 'required|string|max:1000']);
        $old = ['attachment_name' => $supplierInvoice->attachment_name];
        $this->storeAttachment($request, $supplierInvoice);
        $this->audit->record($supplierInvoice, 'attachment_replaced', $old, ['attachment_name' => $supplierInvoice->attachment_name], $request->reason, $supplierInvoice->purchase_order_id);

        return response()->json(['supplier_invoice' => $supplierInvoice]);
    }

    public function downloadAttachment(Request $request, SupplierInvoice $supplierInvoice)
    {
        $this->permit($request, 'supplier_invoices_view');
        $this->assertWarehouse($request, $supplierInvoice->gatePass()->value('warehouse_id'));
        abort_unless($supplierInvoice->attachment_path && Storage::disk('local')->exists($supplierInvoice->attachment_path), 404);

        return Storage::disk('local')->download($supplierInvoice->attachment_path, $supplierInvoice->attachment_name);
    }

    private function storeAttachment(Request $request, SupplierInvoice $invoice): void
    {
        if ($invoice->attachment_path) {
            Storage::disk('local')->delete($invoice->attachment_path);
        }
        $file = $request->file('attachment');
        $path = $file->store('procurement/supplier-invoices', 'local');
        $invoice->update(['attachment_path' => $path, 'attachment_name' => $file->getClientOriginalName(), 'attachment_mime' => $file->getMimeType()]);
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
}
