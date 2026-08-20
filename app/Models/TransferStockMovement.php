<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransferStockMovement extends Model
{
    protected $fillable = [
        'transfer_id', 'transfer_return_id', 'transfer_detail_id', 'product_id', 'product_variant_id',
        'warehouse_id', 'movement_type', 'stock_state', 'quantity', 'reference', 'performed_by',
        'metadata', 'idempotency_key',
    ];

    protected $casts = ['quantity' => 'double', 'metadata' => 'array'];

    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function performer() { return $this->belongsTo(User::class, 'performed_by'); }
}
