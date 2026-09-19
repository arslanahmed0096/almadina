<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollCommissionLink extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:4'];
    public function item() { return $this->belongsTo(PayrollItem::class, 'payroll_item_id'); }
    public function entry() { return $this->belongsTo(EmployeeCommissionEntry::class, 'commission_entry_id'); }
}
