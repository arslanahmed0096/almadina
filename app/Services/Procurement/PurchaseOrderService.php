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
    public function __construct(private TaxCalculationService $taxes, private ProcurementAuditService $audit) {}

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

    public function update(PurchaseOrder $order, array $data): PurchaseOrder
    {
        if ($order->status !== PurchaseOrderStatus::Draft->value) {
            throw ValidationException::withMessages(['status' => ['Only draft Purchase Orders can be edited.']]);
        }

        return DB::transaction(function () use ($order, $data) {
            $old = $order->load('items')->toArray();
            $order->update(collect($data)->only(['order_date', 'expected_delivery_date', 'provider_id', 'warehouse_id', 'notes', 'terms'])->all());
            $this->replaceItems($order, $data['items']);
            $this->audit->record($order, 'updated', $old, $order->fresh('items')->toArray(), $data['reason'] ?? null);

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

    private function replaceItems(PurchaseOrder $order, array $items): void
    {
        if (! $items) {
            throw ValidationException::withMessages(['items' => ['At least one product is required.']]);
        }
        $order->items()->delete();
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
            $order->items()->create([
                'product_id' => $product->id, 'product_variant_id' => $variant?->id, 'unit_id' => $unit?->id,
                'product_name' => $product->name, 'variant_name' => $variant?->name,
                'sku' => $variant?->code ?: $product->code, 'unit_name' => $unit?->ShortName ?: $unit?->name,
                'ordered_quantity' => $qty, 'unit_price' => $price, 'discount' => $discountValue,
                'discount_method' => $method, 'tax_id' => $tax?->id, 'tax_name' => $tax?->name,
                'tax_rate' => $tax?->rate ?? 0, 'tax_amount' => Decimal::round($taxAmount, 6),
                'line_subtotal' => Decimal::round($net, 6), 'line_total' => Decimal::round($total, 6), 'notes' => $row['notes'] ?? null,
            ]);
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
