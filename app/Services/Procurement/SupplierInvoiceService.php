<?php

namespace App\Services\Procurement;

use App\Enums\SupplierInvoiceStatus;
use App\Events\PurchaseCreated;
use App\Events\PurchaseDeleted;
use App\Models\GatePass;
use App\Models\GatePassItem;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\Tax;
use App\Models\TaxPriceType;
use App\Models\TransactionTaxSnapshot;
use App\Models\UserWarehouse;
use App\Services\Tax\Decimal;
use App\Services\Tax\TaxCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierInvoiceService
{
    public function __construct(
        private TaxCalculationService $taxes,
        private ProcurementAuditService $audit,
        private PurchaseOrderProgressService $progress,
        private ProcurementNotificationService $notifications,
    ) {}

    public function create(GatePass $gatePass, array $data, $user): SupplierInvoice
    {
        $this->assertWarehouse($user, $gatePass->warehouse_id);

        return DB::transaction(function () use ($gatePass, $data, $user) {
            $gatePass = GatePass::with('provider')->lockForUpdate()->findOrFail($gatePass->id);
            if (! in_array($gatePass->status, ['accepted', 'partially_accepted'], true)) {
                throw ValidationException::withMessages(['gate_pass_id' => ['Only accepted Gate Pass quantities can be invoiced.']]);
            }
            $defaultTaxType = $gatePass->provider->tax_status ?: 'non_gst';
            $taxType = $data['tax_type'] ?? $defaultTaxType;
            $overridden = $taxType !== $defaultTaxType;
            if ($overridden && ! $user->canProcurement('supplier_tax_override')) {
                abort(403, 'You are not allowed to override the supplier tax default.');
            }
            $invoice = SupplierInvoice::create([
                'number' => 'PENDING-'.Str::uuid(), 'supplier_invoice_number' => trim($data['supplier_invoice_number']),
                'provider_id' => $gatePass->provider_id, 'purchase_order_id' => $gatePass->purchase_order_id,
                'gate_pass_id' => $gatePass->id, 'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'] ?? null,
                'tax_type' => $taxType, 'supplier_strn_number' => $data['supplier_strn_number'] ?? $gatePass->provider->strn_number,
                'supplier_ntn_number' => $data['supplier_ntn_number'] ?? $gatePass->provider->ntn_number,
                'tax_type_overridden' => $overridden, 'tax_type_overridden_by' => $overridden ? $user->id : null,
                'tax_type_overridden_at' => $overridden ? now() : null,
                'other_charges' => $data['other_charges'] ?? 0, 'freight_charges' => $data['freight_charges'] ?? 0,
                'notes' => $data['notes'] ?? null, 'status' => ! empty($data['save_as_draft']) ? SupplierInvoiceStatus::Draft->value : SupplierInvoiceStatus::Recorded->value,
                'created_by' => $user->id,
            ]);
            $invoice->update(['number' => 'SI-'.date('Y').'-'.str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT)]);
            $subtotal = '0';
            $discountTotal = '0';
            $taxTotal = '0';
            foreach ($data['items'] as $index => $row) {
                $gateItem = GatePassItem::with(['purchaseOrderItem', 'product', 'variant'])->where('gate_pass_id', $gatePass->id)->lockForUpdate()->find($row['gate_pass_item_id'] ?? 0);
                if (! $gateItem) {
                    throw ValidationException::withMessages(["items.$index.gate_pass_item_id" => ['The product was not accepted on this Gate Pass.']]);
                }
                $already = (string) DB::table('supplier_invoice_items as i')->join('supplier_invoices as s', 's.id', '=', 'i.supplier_invoice_id')
                    ->where('i.gate_pass_item_id', $gateItem->id)->where('s.status', '<>', SupplierInvoiceStatus::Cancelled->value)->sum('i.quantity');
                $remaining = Decimal::sub($gateItem->accepted_quantity, $already);
                $quantity = (string) $row['quantity'];
                if (bccomp($quantity, '0', 6) !== 1 || bccomp($quantity, $remaining, 6) === 1) {
                    throw ValidationException::withMessages(["items.$index.quantity" => ["Invoice quantity must be positive and cannot exceed the remaining {$remaining}."]]);
                }
                $unitCost = (string) $row['unit_cost'];
                $base = Decimal::mul($unitCost, $quantity);
                $discountValue = (string) ($row['discount'] ?? 0);
                $method = $row['discount_method'] ?? 'fixed';
                $discount = $method === 'percentage' ? Decimal::mul($base, Decimal::div($discountValue, '100')) : $discountValue;
                if (bccomp($discount, $base, 6) === 1) {
                    throw ValidationException::withMessages(["items.$index.discount" => ['Discount exceeds line value.']]);
                }
                $net = Decimal::sub($base, $discount);
                $tax = null;
                $taxRows = [];
                $taxAmount = '0';
                if ($taxType === 'gst' && ! empty($row['tax_id'])) {
                    $tax = Tax::query()->effective($data['invoice_date'])->forTransaction('purchase')->forWarehouse($gatePass->warehouse_id)->findOrFail($row['tax_id']);
                    $taxRows = $this->taxes->calculateLine(Decimal::div($net, $quantity), $quantity, [$tax]);
                    $taxAmount = collect($taxRows)->sum('tax_amount');
                }
                $invoice->items()->create([
                    'gate_pass_item_id' => $gateItem->id, 'product_id' => $gateItem->product_id,
                    'product_variant_id' => $gateItem->product_variant_id, 'product_name' => $gateItem->product_name ?: $gateItem->purchaseOrderItem?->product_name,
                    'variant_name' => $gateItem->variant_name ?: $gateItem->purchaseOrderItem?->variant_name,
                    'sku' => $gateItem->sku ?: $gateItem->purchaseOrderItem?->sku,
                    'quantity' => $quantity, 'unit_cost' => $unitCost, 'discount' => $discountValue,
                    'discount_method' => $method, 'tax_id' => $tax?->id, 'tax_name' => $tax?->name,
                    'tax_rate' => $tax?->rate ?? 0, 'tax_amount' => Decimal::round($taxAmount, 6),
                    'tax_snapshot' => $taxRows ?: null, 'line_subtotal' => Decimal::round($net, 6),
                    'line_total' => Decimal::round(Decimal::add($net, $taxAmount), 6),
                ]);
                $subtotal = Decimal::add($subtotal, $base);
                $discountTotal = Decimal::add($discountTotal, $discount);
                $taxTotal = Decimal::add($taxTotal, $taxAmount);
            }
            $grandTotal = Decimal::add(Decimal::add(Decimal::sub($subtotal, $discountTotal), $taxTotal), Decimal::add($invoice->other_charges, $invoice->freight_charges));
            $invoice->update(['subtotal' => $subtotal, 'discount_total' => $discountTotal, 'tax_total' => $taxTotal, 'grand_total' => Decimal::round($grandTotal, 2)]);
            $this->audit->record($invoice, 'recorded', [], $invoice->load('items')->toArray(), $invoice->notes, $invoice->purchase_order_id);
            if ($overridden) {
                $this->audit->record($invoice, 'tax_type_overridden', ['tax_type' => $defaultTaxType], ['tax_type' => $taxType], $data['tax_override_reason'] ?? null, $invoice->purchase_order_id);
            }
            if ($invoice->purchase_order_id) {
                $this->progress->refreshStatus(PurchaseOrder::findOrFail($invoice->purchase_order_id));
            }
            $this->notifications->send('supplier_invoices_view', $gatePass->warehouse_id, 'supplier_invoice_recorded', "Supplier invoice {$invoice->supplier_invoice_number} was recorded.", $invoice->number, '/app/procurement/supplier-invoices/'.$invoice->id);
            if ($invoice->status === SupplierInvoiceStatus::Recorded->value) {
                $this->notifications->send('purchases_from_supplier_invoice', $gatePass->warehouse_id, 'purchase_ready_for_posting', "Supplier invoice {$invoice->supplier_invoice_number} is ready for Purchase posting.", $invoice->number, '/app/procurement/supplier-invoices/'.$invoice->id);
            }

            return $invoice->fresh(['items.gatePassItem.purchaseOrderItem', 'provider', 'gatePass', 'purchaseOrder']);
        });
    }

    public function record(SupplierInvoice $invoice, $user): SupplierInvoice
    {
        return DB::transaction(function () use ($invoice) {
            $invoice = SupplierInvoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only draft invoices can be recorded.']]);
            }
            $invoice->update(['status' => 'recorded']);
            $this->audit->record($invoice, 'status_changed', ['status' => 'draft'], ['status' => 'recorded'], null, $invoice->purchase_order_id);

            return $invoice;
        });
    }

    public function createPurchase(SupplierInvoice $invoice, $user): Purchase
    {
        $purchase = DB::transaction(function () use ($invoice, $user) {
            $invoice = SupplierInvoice::with(['items.gatePassItem.purchaseOrderItem', 'gatePass'])->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== SupplierInvoiceStatus::Recorded->value) {
                throw ValidationException::withMessages(['status' => ['Only a recorded supplier invoice can be posted as a Purchase.']]);
            }
            if (Purchase::where('supplier_invoice_id', $invoice->id)->exists()) {
                throw ValidationException::withMessages(['supplier_invoice_id' => ['A Purchase already exists for this supplier invoice.']]);
            }
            $purchase = Purchase::create([
                'date' => $invoice->invoice_date, 'time' => now()->toTimeString(), 'Ref' => 'PENDING-'.Str::uuid(),
                'sales_tax_invoice_no' => $invoice->supplier_invoice_number, 'delivery_note_no' => $invoice->gatePass?->supplier_gate_pass_number,
                'purchase_order_id' => $invoice->purchase_order_id, 'gate_pass_id' => $invoice->gate_pass_id,
                'supplier_invoice_id' => $invoice->id, 'invoice_tax_type' => $invoice->tax_type,
                'inventory_already_received' => true, 'posting_status' => 'posted',
                'provider_id' => $invoice->provider_id, 'warehouse_id' => $invoice->gatePass->warehouse_id,
                'GrandTotal' => $invoice->grand_total, 'tax_rate' => 0, 'TaxNet' => $invoice->tax_total,
                'withholding_tax' => 0, 'discount' => $invoice->discount_total,
                'shipping' => Decimal::add($invoice->other_charges, $invoice->freight_charges),
                'statut' => 'received', 'payment_statut' => 'unpaid', 'paid_amount' => 0,
                'notes' => $invoice->notes, 'user_id' => $user->id,
            ]);
            $purchase->update(['Ref' => 'PUR-'.date('Y').'-'.str_pad((string) $purchase->id, 6, '0', STR_PAD_LEFT)]);
            $priceTypeId = TaxPriceType::where('code', 'cost')->value('id');
            foreach ($invoice->items as $item) {
                $detail = PurchaseDetail::create([
                    'purchase_id' => $purchase->id, 'purchase_unit_id' => $item->gatePassItem->unit_id ?: $item->gatePassItem->purchaseOrderItem?->unit_id,
                    'quantity' => $item->quantity, 'product_id' => $item->product_id, 'product_variant_id' => $item->product_variant_id,
                    'cost' => $item->unit_cost, 'company_rb_price' => $item->unit_cost, 'mrp_price' => $item->unit_cost,
                    'TaxNet' => $item->tax_rate, 'sales_tax' => $item->tax_amount, 'withholding_tax' => 0,
                    'discount' => $item->discount, 'discount_method' => $item->discount_method === 'percentage' ? '1' : '2',
                    'tax_method' => '1', 'total' => $item->line_total,
                ]);
                foreach ($item->tax_snapshot ?: [] as $snapshot) {
                    TransactionTaxSnapshot::create($snapshot + [
                        'transaction_type' => 'purchase', 'transaction_id' => $purchase->id,
                        'transaction_line_id' => $detail->id, 'price_type_id' => $priceTypeId,
                        'price_type_code' => 'cost', 'price_type_name' => 'Cost Price', 'quantity' => $item->quantity,
                    ]);
                }
            }
            $invoice->update(['status' => SupplierInvoiceStatus::Posted->value]);
            $this->audit->record($purchase, 'purchase_posted', [], $purchase->toArray(), 'Physical stock was already received by the Gate Pass.', $invoice->purchase_order_id);
            $this->audit->record($invoice, 'posted_to_purchase', ['status' => 'recorded'], ['status' => 'posted', 'purchase_id' => $purchase->id], null, $invoice->purchase_order_id);
            $po = $invoice->purchase_order_id ? PurchaseOrder::findOrFail($invoice->purchase_order_id) : null;
            $poStatus = $po ? $this->progress->refreshStatus($po) : null;
            if ($po && $poStatus === 'completed') {
                $this->notifications->send('purchase_orders_view', $po->warehouse_id, 'purchase_order_completed', "Purchase Order {$po->number} is completed.", $po->number, '/app/procurement/purchase-orders/'.$po->id);
            }

            return $purchase;
        });
        event(new PurchaseCreated($purchase));

        return $purchase->fresh(['details', 'supplierInvoice', 'purchaseOrder', 'gatePass']);
    }

    public function cancel(SupplierInvoice $invoice, $user, string $reason): SupplierInvoice
    {
        $purchaseToReverse = null;
        $invoice = DB::transaction(function () use ($invoice, $user, $reason, &$purchaseToReverse) {
            $invoice = SupplierInvoice::with('purchase')->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === 'cancelled') {
                throw ValidationException::withMessages(['status' => ['This supplier invoice is already cancelled.']]);
            }
            if ($invoice->purchase) {
                $purchaseToReverse = Purchase::lockForUpdate()->findOrFail($invoice->purchase->id);
                if ((float) $purchaseToReverse->paid_amount > 0) {
                    throw ValidationException::withMessages(['purchase' => ['Reverse supplier payments before cancelling this invoice.']]);
                }
                $purchaseToReverse->update(['posting_status' => 'cancelled', 'statut' => 'cancelled']);
                $this->audit->record($purchaseToReverse, 'purchase_cancelled', ['posting_status' => 'posted'], ['posting_status' => 'cancelled'], $reason, $invoice->purchase_order_id);
            }
            $old = ['status' => $invoice->status];
            $invoice->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancellation_reason' => $reason]);
            $this->audit->record($invoice, 'cancelled', $old, ['status' => 'cancelled'], $reason, $invoice->purchase_order_id);
            if ($invoice->purchase_order_id) {
                $this->progress->refreshStatus(PurchaseOrder::findOrFail($invoice->purchase_order_id));
            }

            return $invoice;
        });
        if ($purchaseToReverse) {
            event(new PurchaseDeleted($purchaseToReverse));
        }

        return $invoice->fresh(['purchase']);
    }

    private function assertWarehouse($user, int $warehouseId): void
    {
        if ($user->isSuperAdmin() || $user->is_all_warehouses) {
            return;
        }
        if (! UserWarehouse::where('user_id', $user->id)->where('warehouse_id', $warehouseId)->exists()) {
            abort(403, 'Warehouse access denied.');
        }
    }
}
