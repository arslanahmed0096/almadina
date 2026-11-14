<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseDiscountUsage extends Model
{
    protected $guarded = ['id'];

    public function discount()
    {
        return $this->belongsTo(PurchaseDiscount::class, 'purchase_discount_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function purchaseDetail()
    {
        return $this->belongsTo(PurchaseDetail::class);
    }
}
