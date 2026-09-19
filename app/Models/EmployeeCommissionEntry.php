<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeCommissionEntry extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'stored_profit_per_unit' => 'decimal:4', 'quantity' => 'decimal:4',
        'total_applicable_profit' => 'decimal:4', 'commission_percentage' => 'decimal:4',
        'commission_amount' => 'decimal:4', 'commission_date' => 'date',
    ];
    public function sale() { return $this->belongsTo(Sale::class); }
    public function saleDetail() { return $this->belongsTo(SaleDetail::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function designation() { return $this->belongsTo(Designation::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function ruleVersion() { return $this->belongsTo(RoleCommissionRuleVersion::class, 'rule_version_id'); }
    public function originalEntry() { return $this->belongsTo(self::class, 'original_entry_id'); }
    public function payrollLink() { return $this->hasOne(PayrollCommissionLink::class, 'commission_entry_id'); }
}
