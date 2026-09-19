<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeSalaryHistory extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['monthly_salary' => 'decimal:2', 'effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean'];
    public function employee() { return $this->belongsTo(Employee::class); }
    public function scopeEffectiveAt($query, $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->where('is_active', true);
    }
}
