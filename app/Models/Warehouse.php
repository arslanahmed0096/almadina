<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'name', 'mobile', 'country', 'city', 'email', 'zip', 'ownership_type', 'stock_transfer_company_id',
    ];

    public function assignedUsers()
    {
        return $this->belongsToMany('App\Models\User');
    }

    public function locations()
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    public function targetAllocations()
    {
        return $this->hasMany(SupplierTargetAllocation::class);
    }

    public function stockTransferCompany()
    {
        return $this->belongsTo(StockTransferCompany::class, 'stock_transfer_company_id');
    }
}
