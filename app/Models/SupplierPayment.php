<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    protected $fillable = [
        'reference', 'provider_id', 'user_id', 'payment_method_id', 'account_id',
        'payment_date', 'amount', 'bank_name', 'bank_account_number',
        'transaction_reference', 'transfer_date', 'cheque_number', 'cheque_date',
        'cheque_status', 'clearance_date', 'notes', 'status', 'cancelled_at',
    ];

    protected $casts = [
        'amount' => 'double',
        'payment_date' => 'date:Y-m-d',
        'transfer_date' => 'date:Y-m-d',
        'cheque_date' => 'date:Y-m-d',
        'clearance_date' => 'date:Y-m-d',
        'cancelled_at' => 'datetime',
    ];

    public function provider() { return $this->belongsTo(Provider::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function paymentMethod() { return $this->belongsTo(PaymentMethod::class); }
    public function account() { return $this->belongsTo(Account::class); }
    public function allocations() { return $this->hasMany(PaymentPurchase::class); }
}
