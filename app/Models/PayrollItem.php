<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollItem extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'basic_salary' => 'decimal:2', 'commission' => 'decimal:2', 'bonus' => 'decimal:2',
        'allowances' => 'decimal:2', 'overtime' => 'decimal:2', 'absence_deduction' => 'decimal:2',
        'deductions' => 'decimal:2', 'salary_advance' => 'decimal:2', 'loan_recovery' => 'decimal:2',
        'commission_adjustments' => 'decimal:2', 'gross_salary' => 'decimal:2',
        'total_deductions' => 'decimal:2', 'net_payable' => 'decimal:2',
        'paid_amount' => 'decimal:2', 'remaining_amount' => 'decimal:2',
    ];
    public function period() { return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function designation() { return $this->belongsTo(Designation::class); }
    public function components() { return $this->hasMany(PayrollItemComponent::class); }
    public function commissionLinks() { return $this->hasMany(PayrollCommissionLink::class); }
    public function payments() { return $this->hasMany(PayrollPayment::class); }
}
