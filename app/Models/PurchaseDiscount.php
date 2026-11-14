<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseDiscount extends Model
{
    use SoftDeletes;

    public const CATEGORIES = ['on_invoice', 'further_discount'];
    public const STATUSES = ['active', 'inactive'];

    protected $fillable = ['name', 'category', 'description', 'status'];

    public function transactionUsages()
    {
        return $this->hasMany(PurchaseDiscountUsage::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function getCategoryLabelAttribute(): string
    {
        return match ($this->category) {
            'on_invoice' => 'On Invoice Discount',
            'further_discount' => 'Further Discount',
            default => ucwords(str_replace('_', ' ', (string) $this->category)),
        };
    }
}
