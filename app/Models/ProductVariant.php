<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $table = 'product_variants';

    protected $fillable = [
        'product_id', 'name', 'qty', 'cost', 'company_rb_price', 'mrp_price', 'price', 'fix_price',
        'wholesale', 'min_price', 'code', 'image',
        'woocommerce_variation_id',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'qty' => 'double',
        'cost' => 'double',
        'company_rb_price' => 'double',
        'mrp_price' => 'double',
        'price' => 'double',
        'fix_price' => 'double',
        'wholesale' => 'double',
        'min_price' => 'double',
        'woocommerce_variation_id' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
