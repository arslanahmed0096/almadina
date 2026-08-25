<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatePass extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['delivered_at' => 'datetime', 'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items()
    {
        return $this->hasMany(GatePassItem::class);
    }

    public function supplierInvoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }
}
