<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'name', 'code', 'adresse', 'phone', 'country', 'email', 'city', 'tax_number', 'tax_status',
        'strn_number', 'ntn_number',
        'account_title',
        'opening_balance', 'credit_limit',
    ];

    protected $casts = [
        'code' => 'integer',
        'opening_balance' => 'double',
        'credit_limit' => 'double',
    ];

    /**
     * Get custom field values for this provider
     */
    public function customFieldValues()
    {
        return $this->morphMany(CustomFieldValue::class, 'entity', 'entity_type', 'entity_id');
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function supplierInvoices()
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    /**
     * Item categories supplied by this supplier.
     */
    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_provider', 'provider_id', 'category_id')
            ->withTimestamps();
    }
}
