<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferReturnDetail extends Model
{
    protected $fillable = [
        'transfer_return_id', 'transfer_detail_id', 'product_id', 'product_variant_id',
        'purchase_unit_id', 'quantity', 'reason_code', 'reason_note', 'received_condition', 'identifiers',
    ];

    protected $casts = ['quantity' => 'double', 'identifiers' => 'array'];

    public function transferReturn() { return $this->belongsTo(TransferReturn::class); }
    public function transferDetail() { return $this->belongsTo(TransferDetail::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
