<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class SaleDetail extends Model
{
    protected $fillable = [
        'id', 'date', 'sale_id', 'sale_unit_id', 'quantity', 'product_id', 'total', 'product_variant_id',
        'price', 'invoice_print_price', 'TaxNet', 'discount', 'discount_method', 'tax_method', 'price_type',
        'warranty_date', 'guarantee_date',
    ];

    protected $casts = [
        'id' => 'integer',
        'total' => 'double',
        'quantity' => 'double',
        'sale_id' => 'integer',
        'sale_unit_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'price' => 'double',
        'invoice_print_price' => 'double',
        'TaxNet' => 'double',
        'discount' => 'double',
        'price_type' => 'string',
        'warranty_date' => 'date',
        'guarantee_date' => 'date',
    ];

    public function sale()
    {
        return $this->belongsTo('App\Models\Sale');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function shipmentItem()
    {
        return $this->hasOne(ShipmentItem::class);
    }

    public static function snapshotInvoicePrintPrice(Product $product, $variantId = null): float
    {
        $printPrice = 0.0;
        if ($variantId) {
            $variant = ProductVariant::where('product_id', $product->id)->find($variantId);
            $printPrice = (float) ($variant->invoice_print_price ?? 0);
        }

        if ($printPrice <= 0) {
            $printPrice = (float) ($product->invoice_print_price ?? 0);
        }

        return max(0, $printPrice);
    }

    public function printableUnitPrice(): float
    {
        if ($this->invoice_print_price !== null) {
            return (float) $this->invoice_print_price > 0
                ? (float) $this->invoice_print_price
                : (float) $this->price;
        }

        $variant = $this->product_variant_id ? $this->productVariant : null;
        $printPrice = (float) ($variant->invoice_print_price ?? 0);
        if ($printPrice <= 0) {
            $printPrice = (float) ($this->product->invoice_print_price ?? 0);
        }

        return $printPrice > 0 ? $printPrice : (float) $this->price;
    }

    public function usesInvoicePrintPrice(): bool
    {
        if ($this->invoice_print_price !== null) {
            return (float) $this->invoice_print_price > 0;
        }

        $variant = $this->product_variant_id ? $this->productVariant : null;
        if ((float) ($variant->invoice_print_price ?? 0) > 0) {
            return true;
        }

        return (float) ($this->product->invoice_print_price ?? 0) > 0;
    }

    public function printableLineTotal(): float
    {
        if ($this->usesInvoicePrintPrice()) {
            return round($this->printableUnitPrice() * (float) $this->quantity, 2);
        }

        return round((float) $this->total, 2);
    }

    public function printableDiscountNet(): float
    {
        if ($this->usesInvoicePrintPrice()) {
            return 0.0;
        }

        $storedDiscount = (string) $this->discount_method === '2'
            ? (float) $this->discount
            : (float) $this->price * (float) $this->discount / 100;

        return round($storedDiscount, 2);
    }

    public static function printableSaleSummary(Sale $sale): array
    {
        $details = $sale->relationLoaded('details')
            ? $sale->details
            : $sale->details()->with(['product', 'productVariant'])->get();
        $hasFixedPrintPrice = $details->contains(fn (SaleDetail $detail) => $detail->usesInvoicePrintPrice());

        if (! $hasFixedPrintPrice) {
            $grandTotal = (float) $sale->GrandTotal;
            $paidAmount = (float) ($sale->paid_amount ?? 0);

            return [
                'has_fixed_print_price' => false,
                'subtotal' => round($details->sum(fn (SaleDetail $detail) => $detail->printableLineTotal()), 2),
                'tax' => round((float) ($sale->TaxNet ?? 0), 2),
                'discount' => round((float) ($sale->discount ?? 0), 2),
                'discount_method' => $sale->discount_Method ?? '2',
                'points_discount' => round((float) ($sale->discount_from_points ?? 0), 2),
                'shipping' => round((float) ($sale->shipping ?? 0), 2),
                'grand_total' => round($grandTotal, 2),
                'paid_amount' => round($paidAmount, 2),
                'due' => round(max(0, $grandTotal - $paidAmount), 2),
            ];
        }

        $subtotal = round($details->sum(fn (SaleDetail $detail) => $detail->printableLineTotal()), 2);
        $shipping = round((float) ($sale->shipping ?? 0), 2);
        $grandTotal = round($subtotal + $shipping, 2);
        $actualPaid = (float) ($sale->paid_amount ?? 0);
        $wasFullyPaid = strtolower((string) ($sale->payment_statut ?? '')) === 'paid'
            || $actualPaid >= (float) $sale->GrandTotal - 0.01;
        $paidAmount = $wasFullyPaid ? $grandTotal : min($actualPaid, $grandTotal);

        return [
            'has_fixed_print_price' => true,
            'subtotal' => $subtotal,
            'tax' => 0.0,
            'discount' => 0.0,
            'discount_method' => '2',
            'points_discount' => 0.0,
            'shipping' => $shipping,
            'grand_total' => $grandTotal,
            'paid_amount' => round($paidAmount, 2),
            'due' => round(max(0, $grandTotal - $paidAmount), 2),
        ];
    }

    /**
     * Compute warranty_date and guarantee_date from product warranty/guarantee duration and a base date.
     * Uses product's warranty_period + warranty_unit and (if has_guarantee) guarantee_period + guarantee_unit.
     *
     * @param  Product  $product  Product (or product from variant) with warranty_period, warranty_unit, has_guarantee, guarantee_period, guarantee_unit
     * @param  \Carbon\Carbon|string  $baseDate  Sale date or created_at to add duration to
     * @return array{ warranty_date: ?string, guarantee_date: ?string }  Y-m-d or null
     */
    public static function computeWarrantyGuaranteeDates(Product $product, $baseDate): array
    {
        $base = $baseDate instanceof Carbon ? $baseDate : Carbon::parse($baseDate);

        $warrantyDate = null;
        if ($product->warranty_period !== null && $product->warranty_period > 0 && ! empty($product->warranty_unit)) {
            $warrantyDate = static::addDuration($base, (int) $product->warranty_period, $product->warranty_unit);
        }

        $guaranteeDate = null;
        if (! empty($product->has_guarantee) && $product->guarantee_period !== null && $product->guarantee_period > 0 && ! empty($product->guarantee_unit)) {
            $guaranteeDate = static::addDuration($base, (int) $product->guarantee_period, $product->guarantee_unit);
        }

        return [
            'warranty_date' => $warrantyDate?->format('Y-m-d'),
            'guarantee_date' => $guaranteeDate?->format('Y-m-d'),
        ];
    }

    /**
     * Add a duration to a date using unit (days, months, years).
     */
    protected static function addDuration(Carbon $date, int $period, string $unit): ?Carbon
    {
        $unit = strtolower($unit);
        if ($unit === 'days') {
            return $date->copy()->addDays($period);
        }
        if ($unit === 'months') {
            return $date->copy()->addMonths($period);
        }
        if ($unit === 'years') {
            return $date->copy()->addYears($period);
        }

        return null;
    }
}
