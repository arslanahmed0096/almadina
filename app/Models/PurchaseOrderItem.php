<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'ordered_quantity' => 'decimal:6', 'unit_price' => 'decimal:6', 'discount' => 'decimal:6',
        'tax_rate' => 'decimal:6', 'tax_amount' => 'decimal:6', 'line_subtotal' => 'decimal:6', 'line_total' => 'decimal:6',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function gatePassItems()
    {
        return $this->hasMany(GatePassItem::class);
    }

    public function purchaseDetails()
    {
        return $this->hasMany(PurchaseDetail::class);
    }
}
