<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'order_date' => 'date', 'expected_delivery_date' => 'date', 'issued_at' => 'datetime',
        'cancelled_at' => 'datetime', 'subtotal' => 'decimal:6', 'discount_total' => 'decimal:6',
        'tax_total' => 'decimal:6', 'grand_total' => 'decimal:6',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function gatePasses()
    {
        return $this->hasMany(GatePass::class);
    }

    public function supplierInvoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function audits()
    {
        return $this->hasMany(ProcurementAudit::class)->orderByDesc('created_at');
    }
}
