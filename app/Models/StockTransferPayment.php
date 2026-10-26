<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferPayment extends Model
{
    protected $fillable = ['stock_transfer_company_id', 'warehouse_id', 'payment_date', 'amount', 'payment_method_id', 'account_id', 'reference', 'idempotency_key', 'external_reference', 'remarks', 'received_by', 'status', 'cancelled_at', 'cancelled_by'];
    protected $casts = ['payment_date' => 'date:Y-m-d', 'amount' => 'double', 'cancelled_at' => 'datetime'];

    public function company() { return $this->belongsTo(StockTransferCompany::class, 'stock_transfer_company_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function paymentMethod() { return $this->belongsTo(PaymentMethod::class); }
    public function account() { return $this->belongsTo(Account::class); }
    public function receiver() { return $this->belongsTo(User::class, 'received_by'); }
    public function allocations() { return $this->hasMany(StockTransferPaymentAllocation::class); }
}
