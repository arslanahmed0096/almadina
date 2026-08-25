<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatePassItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'delivered_quantity' => 'decimal:6', 'accepted_quantity' => 'decimal:6',
        'rejected_quantity' => 'decimal:6', 'short_quantity' => 'decimal:6', 'over_delivery_approved' => 'boolean',
    ];

    public function gatePass()
    {
        return $this->belongsTo(GatePass::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
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

    public function supplierInvoiceItems()
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }
}
