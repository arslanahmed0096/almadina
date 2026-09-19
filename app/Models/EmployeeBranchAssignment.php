<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeBranchAssignment extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function designation() { return $this->belongsTo(Designation::class); }

    public function scopeEffectiveAt($query, $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->where('is_active', true);
    }
}
