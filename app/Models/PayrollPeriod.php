<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'commission_cutoff_date' => 'date',
        'payment_date' => 'date', 'generated_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function items() { return $this->hasMany(PayrollItem::class); }
}
