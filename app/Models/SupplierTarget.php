<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierTarget extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'supplier_id', 'target_name', 'period_type', 'start_date', 'end_date', 'measurement_type',
        'status', 'description', 'allocation_requires_review', 'created_by', 'updated_by',
    ];

    protected $casts = ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'allocation_requires_review' => 'boolean'];

    public function supplier()
    {
        return $this->belongsTo(Provider::class, 'supplier_id');
    }

    public function lines()
    {
        return $this->hasMany(SupplierTargetLine::class);
    }

    public function allocations()
    {
        return $this->hasMany(SupplierTargetAllocation::class);
    }

    public function histories()
    {
        return $this->hasMany(SupplierTargetHistory::class)->latest();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function getTotalTargetAttribute(): float
    {
        return round((float) ($this->relationLoaded('lines') ? $this->lines->sum('target_quantity') : $this->lines()->sum('target_quantity')), 3);
    }

    public function getAllocatedTotalAttribute(): float
    {
        return round((float) ($this->relationLoaded('allocations') ? $this->allocations->sum('allocated_quantity') : $this->allocations()->sum('allocated_quantity')), 3);
    }
}
