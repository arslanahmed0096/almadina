<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tax extends Model
{
    use SoftDeletes;

    public const TRANSACTION_TYPES = ['purchase', 'sale_invoice', 'pos', 'sale_return', 'purchase_return'];
    public const CALCULATION_TYPES = ['percentage', 'fixed'];
    public const BEHAVIORS = ['additive', 'deductive', 'inclusive'];

    protected $fillable = [
        'scope_key', 'company_id', 'name', 'code', 'description', 'calculation_type', 'rate', 'behavior',
        'effective_start_date', 'effective_end_date', 'priority', 'is_compound', 'is_active',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'rate' => 'decimal:6', 'priority' => 'integer', 'is_compound' => 'boolean', 'is_active' => 'boolean',
        'effective_start_date' => 'date', 'effective_end_date' => 'date', 'company_id' => 'integer',
    ];

    public function priceTypes() { return $this->belongsToMany(TaxPriceType::class, 'tax_price_type'); }
    public function warehouses() { return $this->belongsToMany(Warehouse::class, 'tax_warehouse'); }
    public function transactionTypes() { return $this->hasMany(TaxTransactionType::class); }
    public function audits() { return $this->hasMany(TaxAudit::class); }
    public function snapshots() { return $this->hasMany(TransactionTaxSnapshot::class); }

    public function scopeEffective(Builder $query, $date = null): Builder
    {
        $date = $date ?: now()->toDateString();
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_start_date')->orWhereDate('effective_start_date', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_end_date')->orWhereDate('effective_end_date', '>=', $date));
    }

    public function scopeForTransaction(Builder $query, string $type): Builder
    {
        return $query->whereHas('transactionTypes', fn ($q) => $q->where('transaction_type', $type));
    }

    public function scopeForWarehouse(Builder $query, ?int $warehouseId): Builder
    {
        return $query->where(function ($q) use ($warehouseId) {
            $q->whereDoesntHave('warehouses');
            if ($warehouseId) $q->orWhereHas('warehouses', fn ($w) => $w->where('warehouses.id', $warehouseId));
        });
    }
}
