<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseCategoryCreditTerm extends Model
{
    protected $fillable = [
        'purchase_id', 'category_id', 'category_name', 'credit_days', 'amount', 'due_date',
    ];

    protected $casts = [
        'purchase_id' => 'integer',
        'category_id' => 'integer',
        'credit_days' => 'integer',
        'amount' => 'double',
        'due_date' => 'date:Y-m-d',
    ];

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }
}
