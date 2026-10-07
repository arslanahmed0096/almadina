<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierTargetLine extends Model
{
    protected $fillable = ['supplier_target_id', 'product_id', 'category_id', 'unit_id', 'target_quantity', 'further_discount_per_unit', 'further_discounts'];

    protected $casts = ['target_quantity' => 'decimal:3', 'further_discount_per_unit' => 'decimal:2', 'further_discounts' => 'array'];

    public function discountRules(): array
    {
        $rules = is_array($this->further_discounts) ? $this->further_discounts : [];
        if ($rules) {
            return $rules;
        }

        return (float) $this->further_discount_per_unit > 0 ? [[
            'label' => 'Further Discount / Unit',
            'type' => 'fixed',
            'value' => (float) $this->further_discount_per_unit,
        ]] : [];
    }

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

    public function allocations()
    {
        return $this->hasMany(SupplierTargetLineAllocation::class, 'supplier_target_line_id');
    }

    public function discountPostings()
    {
        return $this->hasMany(SupplierTargetDiscountPosting::class, 'supplier_target_line_id');
    }
}
