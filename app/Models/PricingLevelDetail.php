<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingLevelDetail extends Model
{
    protected $fillable = [
        'pricing_level_id', 'product_id', 'product_variant_id',
        'company_rb_price', 'mrp_price', 'cost', 'purchase_price', 'pricing_margins', 'fix_price',
        'price', 'wholesale_price', 'min_price',
        'previous_company_rb_price', 'previous_mrp_price', 'previous_cost', 'previous_purchase_price',
        'previous_pricing_margins', 'previous_fix_price', 'previous_price',
        'previous_wholesale_price', 'previous_min_price',
    ];

    protected $casts = [
        'pricing_level_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'company_rb_price' => 'double',
        'mrp_price' => 'double',
        'cost' => 'double',
        'purchase_price' => 'double',
        'pricing_margins' => 'array',
        'fix_price' => 'double',
        'price' => 'double',
        'wholesale_price' => 'double',
        'min_price' => 'double',
        'previous_company_rb_price' => 'double',
        'previous_mrp_price' => 'double',
        'previous_cost' => 'double',
        'previous_purchase_price' => 'double',
        'previous_pricing_margins' => 'array',
        'previous_fix_price' => 'double',
        'previous_price' => 'double',
        'previous_wholesale_price' => 'double',
        'previous_min_price' => 'double',
    ];

    public function pricingLevel()
    {
        return $this->belongsTo(PricingLevel::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
