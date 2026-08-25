<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:6', 'unit_cost' => 'decimal:6', 'discount' => 'decimal:6',
        'tax_rate' => 'decimal:6', 'tax_amount' => 'decimal:6', 'tax_snapshot' => 'array',
        'line_subtotal' => 'decimal:6', 'line_total' => 'decimal:6',
    ];

    public function supplierInvoice()
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function gatePassItem()
    {
        return $this->belongsTo(GatePassItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
