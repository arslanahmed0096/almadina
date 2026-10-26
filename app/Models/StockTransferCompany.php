<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransferCompany extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'code', 'phone', 'email', 'address', 'opening_balance', 'is_active', 'notes'];
    protected $casts = ['opening_balance' => 'double', 'is_active' => 'boolean'];

    public function warehouses() { return $this->hasMany(Warehouse::class, 'stock_transfer_company_id'); }
    public function transfers() { return $this->hasMany(Transfer::class, 'stock_transfer_company_id'); }
    public function payments() { return $this->hasMany(StockTransferPayment::class); }
    public function ledgerEntries() { return $this->hasMany(StockTransferLedgerEntry::class); }
}
