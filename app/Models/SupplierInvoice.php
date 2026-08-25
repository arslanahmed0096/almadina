<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoice extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'invoice_date' => 'date', 'due_date' => 'date', 'tax_type_overridden' => 'boolean',
        'tax_type_overridden_at' => 'datetime', 'cancelled_at' => 'datetime', 'subtotal' => 'decimal:6',
        'discount_total' => 'decimal:6', 'tax_total' => 'decimal:6', 'other_charges' => 'decimal:6',
        'freight_charges' => 'decimal:6', 'grand_total' => 'decimal:6',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function gatePass()
    {
        return $this->belongsTo(GatePass::class);
    }

    public function items()
    {
        return $this->hasMany(SupplierInvoiceItem::class);
    }

    public function purchase()
    {
        return $this->hasOne(Purchase::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
