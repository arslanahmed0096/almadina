<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferLedgerEntry extends Model
{
    protected $fillable = ['stock_transfer_company_id', 'warehouse_id', 'transfer_id', 'stock_transfer_payment_id', 'transfer_return_id', 'reversal_of_id', 'entry_date', 'entry_type', 'reference', 'debit', 'credit', 'notes', 'posted_by', 'idempotency_key', 'metadata'];
    protected $casts = ['entry_date' => 'date:Y-m-d', 'debit' => 'double', 'credit' => 'double', 'metadata' => 'array'];

    public function company() { return $this->belongsTo(StockTransferCompany::class, 'stock_transfer_company_id'); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function transfer() { return $this->belongsTo(Transfer::class); }
    public function payment() { return $this->belongsTo(StockTransferPayment::class, 'stock_transfer_payment_id'); }
}
