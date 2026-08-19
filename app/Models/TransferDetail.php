<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferDetail extends Model
{
    protected $table = 'transfer_details';

    protected $fillable = [
        'id', 'transfer_id', 'quantity', 'purchase_unit_id', 'product_id', 'total', 'product_variant_id',
        'cost', 'TaxNet', 'discount', 'discount_method', 'tax_method',
        'requested_quantity', 'approved_quantity', 'dispatched_quantity', 'received_quantity',
        'decision_status', 'response_reason', 'requested_batches',
    ];

    protected $casts = [
        'total' => 'double',
        'cost' => 'double',
        'TaxNet' => 'double',
        'discount' => 'double',
        'quantity' => 'double',
        'transfer_id' => 'integer',
        'purchase_unit_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'requested_quantity' => 'double',
        'approved_quantity' => 'double',
        'dispatched_quantity' => 'double',
        'received_quantity' => 'double',
        'requested_batches' => 'array',
    ];

    public function transfer()
    {
        return $this->belongsTo('App\Models\Transfer');
    }

    public function product()
    {
        return $this->belongsTo('App\Models\Product');
    }

    public function batches()
    {
        return $this->hasMany(TransferDetailBatch::class, 'transfer_detail_id');
    }
}
