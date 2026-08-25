<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxTransactionType extends Model
{
    public $timestamps = false;
    protected $fillable = ['tax_id', 'transaction_type'];
    public function tax() { return $this->belongsTo(Tax::class); }
}
