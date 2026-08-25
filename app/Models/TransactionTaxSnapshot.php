<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionTaxSnapshot extends Model
{
    protected $fillable = [
        'transaction_type', 'transaction_id', 'transaction_line_id', 'tax_id', 'tax_name', 'tax_code',
        'calculation_type', 'rate', 'behavior', 'price_type_id', 'price_type_code', 'price_type_name',
        'quantity', 'taxable_base', 'tax_amount', 'priority', 'is_compound', 'is_reversal', 'reversal_of_id',
    ];
    protected $casts = [
        'rate' => 'decimal:6', 'quantity' => 'decimal:6', 'taxable_base' => 'decimal:6',
        'tax_amount' => 'decimal:6', 'priority' => 'integer', 'is_compound' => 'boolean', 'is_reversal' => 'boolean',
    ];
    public function tax() { return $this->belongsTo(Tax::class); }
    public function reversalOf() { return $this->belongsTo(self::class, 'reversal_of_id'); }
}
