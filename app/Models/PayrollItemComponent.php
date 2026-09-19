<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollItemComponent extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['amount' => 'decimal:2'];
    public function item() { return $this->belongsTo(PayrollItem::class, 'payroll_item_id'); }
}
