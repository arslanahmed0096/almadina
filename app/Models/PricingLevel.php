<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PricingLevel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'date', 'time', 'brand_id', 'category_id', 'user_id', 'total_products',
    ];

    protected $casts = [
        'date' => 'date',
        'brand_id' => 'integer',
        'category_id' => 'integer',
        'user_id' => 'integer',
        'total_products' => 'integer',
    ];

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function details()
    {
        return $this->hasMany(PricingLevelDetail::class);
    }
}
