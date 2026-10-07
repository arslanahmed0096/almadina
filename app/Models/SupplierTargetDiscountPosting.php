<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierTargetDiscountPosting extends Model
{
    protected $fillable = ['supplier_target_id', 'supplier_target_line_id', 'posting_date', 'amount', 'reference', 'notes', 'created_by'];
    protected $casts = ['posting_date' => 'date', 'amount' => 'decimal:2'];
    public function target() { return $this->belongsTo(SupplierTarget::class, 'supplier_target_id'); }
    public function line() { return $this->belongsTo(SupplierTargetLine::class, 'supplier_target_line_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
