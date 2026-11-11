<?php

namespace App\Services\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Provider;
use App\Models\PurchaseOrder;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\Tax\Decimal;
use App\Services\Tax\TaxCalculationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function __construct(
        private TaxCalculationService $taxes,
        private ProcurementAuditService $audit,
        private PurchaseOrderProgressService $progress,
    ) {}

    public function create(array $data, $user): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $user) {
            $provider = Provider::findOrFail($data['provider_id']);
            $order = PurchaseOrder::create([
                'number' => 'PENDING-'.Str::uuid(),
                'order_date' => $data['order_date'], 'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'provider_id' => $provider->id, 'warehouse_id' => $data['warehouse_id'], 'created_by' => $user->id,
                'status' => PurchaseOrderStatus::Draft->value,
                'supplier_contact_snapshot' => collect([$provider->name, $provider->phone, $provider->email, $provider->adresse])->filter()->implode(' | '),
                'notes' => $data['notes'] ?? null, 'terms' => $data['terms'] ?? null,
            ]);
            $order->update(['number' => 'PO-'.date('Y').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT)]);
            $this->replaceItems($order, $data['items']);
            $this->audit->record($order, 'created', [], $order->fresh()->toArray(), $data['notes'] ?? null);

            return $order->fresh(['items', 'provider', 'warehouse']);
        });
    }

    public function update(PurchaseOrder $order, array $data, bool $allowActiveOrder = false): PurchaseOrder
    {
        if ($order->status !== PurchaseOrderStatus::Draft->value
            && ! ($allowActiveOrder && $order->status !== PurchaseOrderStatus::Cancelled->value)) {
            throw ValidationException::withMessages(['status' => ['Only draft Purchase Orders can be edited unless the user is Superadmin.']]);
        }

        return DB::transaction(function () use ($order, $data) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            $old = $order->load('items')->toArray();
            $hasDependencies = $order->gatePasses()->exists()
                || $order->supplierInvoices()->exists()
                || $order->purchases()->exists();
            if ($hasDependencies && ((int) $data['provider_id'] !== (int) $order->provider_id
                || (int) $data['warehouse_id'] !== (int) $order->warehouse_id)) {
                throw ValidationException::withMessages([
                    'purchase_order' => ['Supplier and destination cannot be changed after Gate Pass, invoice, or purchase activity exists.'],
                ]);
            }
            $order->update(collect($data)->only(['order_date', 'expected_delivery_date', 'provider_id', 'warehouse_id', 'notes', 'terms'])->all());
            $this->replaceItems($order, $data['items'], true);
            $this->audit->record($order, 'updated', $old, $order->fresh('items')->toArray(), $data['reason'] ?? null);
            if ($order->status !== PurchaseOrderStatus::Draft->value) {
                $this->progress->refreshStatus($order);
            }

            return $order->fresh(['items', 'provider', 'warehouse']);
        });
    }

    public function issue(PurchaseOrder $order, $user): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $user) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            if ($order->status !== PurchaseOrderStatus::Draft->value || ! $order->items()->exists()) {
                throw ValidationException::withMessages(['status' => ['Only a non-empty draft Purchase Order can be issued.']]);
            }
            $order->update(['status' => PurchaseOrderStatus::Issued->value, 'issued_at' => now(), 'issued_by' => $user->id]);
            $this->audit->record($order, 'issued', ['status' => 'draft'], ['status' => 'issued']);

            return $order;
        });
    }

    private function replaceItems(PurchaseOrder $order, array $items, bool $preserveReferencedItems = false): void
    {
        if (! $items) {
            throw ValidationException::withMessages(['items' => ['At least one product is required.']]);
        }

        $existingItems = $preserveReferencedItems
            ? $order->items()->lockForUpdate()->get()->keyBy(fn ($item) => $item->product_id.':'.($item->product_variant_id ?: 0))
            : collect();
        $referencedItemIds = collect();
        if ($preserveReferencedItems && $existingItems->isNotEmpty()) {
            $referencedItemIds = DB::table('gate_pass_items')
                ->whereIn('purchase_order_item_id', $existingItems->pluck('id'))
                ->pluck('purchase_order_item_id')
                ->merge(DB::table('purchase_details')
                    ->whereIn('purchase_order_item_id', $existingItems->pluck('id'))
                    ->pluck('purchase_order_item_id'))
                ->unique()
                ->values();
            $submittedKeys = collect($items)->map(fn ($row) => (int) $row['product_id'].':'.((int) ($row['product_variant_id'] ?? 0)));
            $removedReferencedItem = $existingItems->first(fn ($item, $key) => $referencedItemIds->contains($item->id) && ! $submittedKeys->contains($key));
            if ($removedReferencedItem) {
                throw ValidationException::withMessages([
                    'items' => ["{$removedReferencedItem->product_name} cannot be removed because it is already linked to receiving or purchase activity."],
                ]);
            }
            $removableIds = $existingItems
                ->reject(fn ($item, $key) => $submittedKeys->contains($key) || $referencedItemIds->contains($item->id))
                ->pluck('id');
            if ($removableIds->isNotEmpty()) {
                $order->items()->whereIn('id', $removableIds)->delete();
            }
        } else {
            $order->items()->delete();
        }

        $subtotal = '0';
        $discountTotal = '0';
        $taxTotal = '0';
        $grandTotal = '0';
        foreach ($items as $index => $row) {
            $product = Product::whereNull('deleted_at')->findOrFail($row['product_id']);
            $variant = ! empty($row['product_variant_id']) ? ProductVariant::where('product_id', $product->id)->findOrFail($row['product_variant_id']) : null;
            $unit = ! empty($row['unit_id']) ? Unit::findOrFail($row['unit_id']) : $product->unitPurchase;
            $qty = (string) $row['quantity'];
            $price = (string) ($row['unit_price'] ?? 0);
            $base = Decimal::mul($qty, $price);
            $discountValue = (string) ($row['discount'] ?? 0);
            $method = $row['discount_method'] ?? 'fixed';
            $discount = $method === 'percentage' ? Decimal::mul($base, Decimal::div($discountValue, '100')) : $discountValue;
            if (bccomp($discount, $base, 6) === 1) {
                throw ValidationException::withMessages(["items.$index.discount" => ['Discount exceeds the line subtotal.']]);
            }
            $net = Decimal::sub($base, $discount);
            $tax = null;
            $taxAmount = '0';
            if (! empty($row['tax_id'])) {
                $tax = Tax::where('is_active', true)->findOrFail($row['tax_id']);
                $taxAmount = collect($this->taxes->calculateLine(Decimal::div($net, $qty), $qty, [$tax]))->sum('tax_amount');
            }
            $total = Decimal::add($net, $taxAmount);
            $key = $product->id.':'.($variant?->id ?: 0);
            $existingItem = $existingItems->get($key);
            if ($existingItem && $referencedItemIds->contains($existingItem->id)) {
                if ((int) ($existingItem->unit_id ?: 0) !== (int) ($unit?->id ?: 0)) {
                    throw ValidationException::withMessages([
                        "items.$index.unit_id" => ["The unit for {$existingItem->product_name} cannot be changed after receiving or purchase activity exists."],
                    ]);
                }

                $received = (string) DB::table('gate_pass_items as gate_items')
                    ->join('gate_passes', 'gate_passes.id', '=', 'gate_items.gate_pass_id')
                    ->where('gate_items.purchase_order_item_id', $existingItem->id)
                    ->whereIn('gate_passes.status', ['accepted', 'partially_accepted'])
                    ->sum('gate_items.accepted_quantity');
                $invoiced = (string) DB::table('supplier_invoice_items as invoice_items')
                    ->join('supplier_invoices as invoices', 'invoices.id', '=', 'invoice_items.supplier_invoice_id')
                    ->join('gate_pass_items as gate_items', 'gate_items.id', '=', 'invoice_items.gate_pass_item_id')
                    ->where('gate_items.purchase_order_item_id', $existingItem->id)
                    ->where('invoices.status', '<>', 'cancelled')
                    ->sum('invoice_items.quantity');
                $purchased = (string) DB::table('purchase_details as details')
                    ->join('purchases', 'purchases.id', '=', 'details.purchase_id')
                    ->where('details.purchase_order_item_id', $existingItem->id)
                    ->whereNull('purchases.deleted_at')
                    ->where('purchases.posting_status', '<>', 'cancelled')
                    ->sum('details.quantity');
                $committed = collect([$received, $invoiced, $purchased])
                    ->sort(fn ($left, $right) => bccomp((string) $right, (string) $left, 6))
                    ->first();
                if (bccomp($qty, (string) $committed, 6) === -1) {
                    throw ValidationException::withMessages([
                        "items.$index.quantity" => ["{$existingItem->product_name} cannot be reduced below {$committed}; that quantity is already received, invoiced, or purchased."],
                    ]);
                }
            }

            $attributes = [
                'product_id' => $product->id, 'product_variant_id' => $variant?->id, 'unit_id' => $unit?->id,
                'product_name' => $product->name, 'variant_name' => $variant?->name,
                'sku' => $variant?->code ?: $product->code, 'unit_name' => $unit?->ShortName ?: $unit?->name,
                'ordered_quantity' => $qty, 'unit_price' => $price, 'discount' => $discountValue,
                'discount_method' => $method, 'tax_id' => $tax?->id, 'tax_name' => $tax?->name,
                'tax_rate' => $tax?->rate ?? 0, 'tax_amount' => Decimal::round($taxAmount, 6),
                'line_subtotal' => Decimal::round($net, 6), 'line_total' => Decimal::round($total, 6), 'notes' => $row['notes'] ?? null,
            ];
            $existingItem ? $existingItem->update($attributes) : $order->items()->create($attributes);
            $subtotal = Decimal::add($subtotal, $base);
            $discountTotal = Decimal::add($discountTotal, $discount);
            $taxTotal = Decimal::add($taxTotal, $taxAmount);
            $grandTotal = Decimal::add($grandTotal, $total);
        }
        $order->update([
            'subtotal' => $subtotal, 'discount_total' => $discountTotal,
            'tax_total' => $taxTotal, 'grand_total' => $grandTotal,
        ]);
    }
}
