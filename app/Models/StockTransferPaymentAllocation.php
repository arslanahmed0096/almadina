<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferPaymentAllocation extends Model
{
    protected $fillable = ['stock_transfer_payment_id', 'transfer_id', 'amount', 'allocation_type', 'allocated_by'];
    protected $casts = ['amount' => 'double'];

    public function payment() { return $this->belongsTo(StockTransferPayment::class, 'stock_transfer_payment_id'); }
    public function transfer() { return $this->belongsTo(Transfer::class); }
}
