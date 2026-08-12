<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentItem extends Model
{
    protected $fillable = [
        'shipment_id',
        'sale_detail_id',
        'shipped_by',
        'delivery_method',
        'driver_name',
        'item_total',
        'paid_amount',
        'outstanding_amount',
        'credit_amount',
        'shipped_at',
    ];

    protected $casts = [
        'shipment_id' => 'integer',
        'sale_detail_id' => 'integer',
        'shipped_by' => 'integer',
        'item_total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'outstanding_amount' => 'decimal:2',
        'credit_amount' => 'decimal:2',
        'shipped_at' => 'datetime',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function saleDetail()
    {
        return $this->belongsTo(SaleDetail::class);
    }

    public function shippedBy()
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }
}
