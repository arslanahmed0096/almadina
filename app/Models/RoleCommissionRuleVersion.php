<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleCommissionRuleVersion extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'almadina_percentage' => 'decimal:4', 'wholesale_percentage' => 'decimal:4',
        'minimum_percentage' => 'decimal:4', 'is_active' => 'boolean',
        'overallocation_confirmed' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date',
    ];
    public function designation() { return $this->belongsTo(Designation::class); }
    public function scopeEffectiveAt($query, $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }
}
