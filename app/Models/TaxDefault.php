<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxDefault extends Model
{
    protected $fillable = ['scope_key', 'company_id', 'warehouse_id', 'transaction_type', 'tax_id', 'updated_by'];
    public function tax() { return $this->belongsTo(Tax::class); }
}
