<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxPriceType extends Model
{
    protected $fillable = ['code', 'name', 'product_field', 'is_purchase', 'is_sale', 'sort_order', 'is_active'];
    protected $casts = ['is_purchase' => 'boolean', 'is_sale' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];

    public function taxes()
    {
        return $this->belongsToMany(Tax::class, 'tax_price_type');
    }
}
