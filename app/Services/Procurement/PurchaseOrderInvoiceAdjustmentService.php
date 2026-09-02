<?php

namespace App\Services\Procurement;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Unit;
use App\Services\Tax\Decimal;
use Illuminate\Validation\ValidationException;

class PurchaseOrderInvoiceAdjustmentService
{
    public function __construct(
        private ProcurementAuditService $audit,
    ) {}

    /**
     * Add invoice-only excess quantities to an issued PO without receiving stock.
     * Returns the line mappings with their final purchase_order_item_id values.
     */
    public function apply(PurchaseOrder $order, Purchase $purchase, array $details, array $mappings): array
    {
        $order = PurchaseOrder::with('items')->lockForUpdate()->findOrFail($order->id);
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages([
                'gate_pass_ids' => ['Invoice excess cannot revise a cancelled Purchase Order.'],
            ]);
        }

        $changes = [];
        foreach ($mappings as $index => $mapping) {
            $excess = (float) ($mapping['invoice_excess_quantity'] ?? 0);
            if ($excess <= 0) {
                continue;
            }

            $row = $details[$index];
            $item = ! empty($mapping['purchase_order_item_id'])
                ? PurchaseOrderItem::where('purchase_order_id', $order->id)->lockForUpdate()->findOrFail($mapping['purchase_order_item_id'])
                : $this->matchingItem($order, $row);

            if (! $item) {
                $item = $this->createItem($order, $row);
                $oldQuantity = 0.0;
            } else {
                $oldQuantity = (float) $item->ordered_quantity;
                $item->ordered_quantity = (float) Decimal::add((string) $item->ordered_quantity, (string) $excess);
                $this->recalculateItem($item);
            }

            $mappings[$index]['purchase_order_item_id'] = $item->id;
            $changes[] = [
                'purchase_order_item_id' => $item->id,
                'product' => $item->product_name,
                'sku' => $item->sku,
                'previous_ordered_quantity' => $oldQuantity,
                'invoice_excess_quantity' => $excess,
                'revised_ordered_quantity' => (float) $item->ordered_quantity,
            ];
        }

        if (! $changes) {
            return $mappings;
        }

        $this->recalculateOrder($order);
        $notes = 'Purchase '.$purchase->Ref.' added invoice-only excess: '.collect($changes)
            ->map(fn ($change) => "{$change['product']} +{$change['invoice_excess_quantity']} ({$change['previous_ordered_quantity']} to {$change['revised_ordered_quantity']})")
            ->implode('; ').'. The excess quantity was added to stock by this Purchase invoice; the original Gate Pass quantity was not posted twice.';
        $this->audit->record(
            $order,
            'invoice_excess_revised',
            ['purchase_order_items' => collect($changes)->map(fn ($change) => [
                'purchase_order_item_id' => $change['purchase_order_item_id'],
                'ordered_quantity' => $change['previous_ordered_quantity'],
            ])->all()],
            ['purchase' => $purchase->Ref, 'purchase_order_items' => $changes],
            $notes,
            $order->id
        );
        return $mappings;
    }

    private function matchingItem(PurchaseOrder $order, array $row): ?PurchaseOrderItem
    {
        return PurchaseOrderItem::where('purchase_order_id', $order->id)
            ->where('product_id', (int) $row['product_id'])
            ->when(
                ! empty($row['product_variant_id']),
                fn ($query) => $query->where('product_variant_id', (int) $row['product_variant_id']),
                fn ($query) => $query->whereNull('product_variant_id')
            )
            ->when(
                ! empty($row['purchase_unit_id']),
                fn ($query) => $query->where('unit_id', (int) $row['purchase_unit_id']),
                fn ($query) => $query->whereNull('unit_id')
            )
            ->lockForUpdate()
            ->first();
    }

    private function createItem(PurchaseOrder $order, array $row): PurchaseOrderItem
    {
        $product = Product::whereNull('deleted_at')->findOrFail($row['product_id']);
        $variant = ! empty($row['product_variant_id'])
            ? ProductVariant::where('product_id', $product->id)->findOrFail($row['product_variant_id'])
            : null;
        $unit = ! empty($row['purchase_unit_id']) ? Unit::findOrFail($row['purchase_unit_id']) : $product->unitPurchase;
        $quantity = (string) $row['quantity'];
        $price = (string) ($row['Unit_cost'] ?? 0);
        $discount = (string) ($row['discount'] ?? 0);
        $discountMethod = (string) ($row['discount_Method'] ?? '2');
        $taxRate = (string) ($row['tax_percent'] ?? 0);

        $item = $order->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'unit_id' => $unit?->id,
            'product_name' => $product->name,
            'variant_name' => $variant?->name,
            'sku' => $variant?->code ?: $product->code,
            'unit_name' => $unit?->ShortName ?: $unit?->name,
            'ordered_quantity' => $quantity,
            'unit_price' => $price,
            'discount' => $discount,
            'discount_method' => $discountMethod === '1' ? 'percentage' : 'fixed',
            'tax_id' => null,
            'tax_name' => bccomp($taxRate, '0', 6) === 1 ? 'Invoice tax' : null,
            'tax_rate' => $taxRate,
            'tax_amount' => 0,
            'line_subtotal' => 0,
            'line_total' => 0,
            'notes' => 'Added automatically from invoice excess on '.$order->number,
        ]);
        $this->recalculateItem($item);

        return $item->fresh();
    }

    private function recalculateItem(PurchaseOrderItem $item): void
    {
        $base = Decimal::mul((string) $item->ordered_quantity, (string) $item->unit_price);
        $discount = $item->discount_method === 'percentage'
            ? Decimal::mul($base, Decimal::div((string) $item->discount, '100'))
            : (string) $item->discount;
        if (bccomp($discount, $base, 6) === 1) {
            $discount = $base;
        }
        $net = Decimal::sub($base, $discount);
        $tax = Decimal::mul($net, Decimal::div((string) $item->tax_rate, '100'));
        $item->forceFill([
            'tax_amount' => Decimal::round($tax, 6),
            'line_subtotal' => Decimal::round($net, 6),
            'line_total' => Decimal::round(Decimal::add($net, $tax), 6),
        ])->save();
    }

    private function recalculateOrder(PurchaseOrder $order): void
    {
        $items = $order->items()->get();
        $subtotal = '0';
        $discountTotal = '0';
        $taxTotal = '0';
        $grandTotal = '0';
        foreach ($items as $item) {
            $base = Decimal::mul((string) $item->ordered_quantity, (string) $item->unit_price);
            $discount = $item->discount_method === 'percentage'
                ? Decimal::mul($base, Decimal::div((string) $item->discount, '100'))
                : (string) $item->discount;
            $subtotal = Decimal::add($subtotal, $base);
            $discountTotal = Decimal::add($discountTotal, $discount);
            $taxTotal = Decimal::add($taxTotal, (string) $item->tax_amount);
            $grandTotal = Decimal::add($grandTotal, (string) $item->line_total);
        }
        $order->forceFill([
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
        ])->save();
    }
}
