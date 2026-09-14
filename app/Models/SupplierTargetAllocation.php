<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierTargetAllocation extends Model
{
    protected $fillable = ['supplier_target_id', 'warehouse_id', 'allocated_quantity'];

    protected $casts = ['allocated_quantity' => 'decimal:3'];

    public function target()
    {
        return $this->belongsTo(SupplierTarget::class, 'supplier_target_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
