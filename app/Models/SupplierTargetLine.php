<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierTargetLine extends Model
{
    protected $fillable = ['supplier_target_id', 'product_id', 'category_id', 'unit_id', 'target_quantity'];

    protected $casts = ['target_quantity' => 'decimal:3'];

    public function target()
    {
        return $this->belongsTo(SupplierTarget::class, 'supplier_target_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
