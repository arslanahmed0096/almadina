<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingLevelDetail extends Model
{
    protected $fillable = [
        'pricing_level_id', 'product_id', 'product_variant_id',
        'company_rb_price', 'mrp_price', 'cost', 'fix_price',
        'price', 'wholesale_price', 'min_price',
    ];

    protected $casts = [
        'pricing_level_id' => 'integer',
        'product_id' => 'integer',
        'product_variant_id' => 'integer',
        'company_rb_price' => 'double',
        'mrp_price' => 'double',
        'cost' => 'double',
        'fix_price' => 'double',
        'price' => 'double',
        'wholesale_price' => 'double',
        'min_price' => 'double',
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
