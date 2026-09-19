<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPayment extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:2', 'payment_date' => 'date', 'processed_at' => 'datetime'];
    public function item() { return $this->belongsTo(PayrollItem::class, 'payroll_item_id'); }
    public function account() { return $this->belongsTo(Account::class); }
    public function paymentMethod() { return $this->belongsTo(PaymentMethod::class); }
}
