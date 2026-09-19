<?php

namespace App\Services;

use App\Models\Account;
use App\Models\EmployeeBranchAssignment;
use App\Models\EmployeeCommissionEntry;
use App\Models\EmployeeSalaryHistory;
use App\Models\PaymentMethod;
use App\Models\PayrollCommissionLink;
use App\Models\PayrollItem;
use App\Models\PayrollItemComponent;
use App\Models\PayrollPayment;
use App\Models\PayrollPeriod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonthlyPayrollService
{
    public function preview(array $data): array
    {
        $start = Carbon::create((int) $data['year'], (int) $data['month'], 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $cutoff = Carbon::parse($data['commission_cutoff_date']);
        if ($cutoff->lt($start) || $cutoff->gt($end)) {
            throw ValidationException::withMessages(['commission_cutoff_date' => ['The cutoff must be inside the selected payroll month.']]);
        }
        $assignments = EmployeeBranchAssignment::with(['employee', 'designation', 'warehouse'])
            ->where('warehouse_id', $data['warehouse_id'])->where('is_active', true)
            ->where('effective_from', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start->toDateString()))
            ->whereHas('employee', function ($q) use ($start, $end) {
                $q->whereNull('deleted_at')
                    ->where(fn ($e) => $e->whereNull('joining_date')->orWhere('joining_date', '<=', $end->toDateString()))
                    ->where(fn ($e) => $e->whereNull('leaving_date')->orWhere('leaving_date', '>=', $start->toDateString()));
            })->orderByDesc('effective_from')->get()->unique('employee_id');

        $overrides = collect($data['items'] ?? [])->keyBy('employee_id');
        return $assignments->map(function ($assignment) use ($start, $end, $cutoff, $overrides) {
            $salary = EmployeeSalaryHistory::effectiveAt($end->toDateString())
                ->where('employee_id', $assignment->employee_id)->orderByDesc('effective_from')->first();
            $commissions = EmployeeCommissionEntry::where('warehouse_id', $assignment->warehouse_id)
                ->where('employee_id', $assignment->employee_id)->where('status', 'accrued')
                ->whereDoesntHave('payrollLink')
                ->where(function ($q) use ($start, $cutoff) {
                    $q->where(function ($positive) use ($start, $cutoff) {
                        $positive->where('entry_type', 'accrual')
                            ->whereBetween('commission_date', [$start->toDateString(), $cutoff->toDateString()]);
                    })->orWhere(function ($negative) use ($cutoff) {
                        $negative->where('entry_type', 'reversal')->where('commission_date', '<=', $cutoff->toDateString());
                    });
                })->orderBy('id')->get();
            $positive = (float) $commissions->where('commission_amount', '>', 0)->sum('commission_amount');
            $negative = (float) $commissions->where('commission_amount', '<', 0)->sum('commission_amount');
            $input = $overrides->get($assignment->employee_id, []);
            $basic = (float) ($salary?->monthly_salary ?? 0);
            $bonus = (float) ($input['bonus'] ?? 0); $allowances = (float) ($input['allowances'] ?? 0);
            $overtime = (float) ($input['overtime'] ?? 0); $absence = (float) ($input['absence_deduction'] ?? 0);
            $deductions = (float) ($input['deductions'] ?? 0); $advance = (float) ($input['salary_advance'] ?? 0);
            $loan = (float) ($input['loan_recovery'] ?? 0);
            $gross = $basic + $positive + $bonus + $allowances + $overtime;
            $totalDeductions = $absence + $deductions + $advance + $loan + abs($negative);
            return [
                'employee_id' => $assignment->employee_id,
                'employee' => $assignment->employee->username,
                'designation_id' => $assignment->designation_id,
                'role' => $assignment->designation->designation,
                'warehouse_id' => $assignment->warehouse_id,
                'branch' => $assignment->warehouse->name,
                'basic_salary' => round($basic, 2), 'commission' => round($positive, 2),
                'bonus' => round($bonus, 2), 'allowances' => round($allowances, 2), 'overtime' => round($overtime, 2),
                'absence_deduction' => round($absence, 2), 'deductions' => round($deductions, 2),
                'salary_advance' => round($advance, 2), 'loan_recovery' => round($loan, 2),
                'commission_adjustments' => round($negative, 2), 'gross_salary' => round($gross, 2),
                'total_deductions' => round($totalDeductions, 2), 'net_payable' => round(max(0, $gross - $totalDeductions), 2),
                'commission_entry_ids' => $commissions->pluck('id')->all(),
                'status' => 'draft',
            ];
        })->values()->all();
    }

    public function generate(array $data, int $userId): PayrollPeriod
    {
        return DB::transaction(function () use ($data, $userId) {
            $exists = PayrollPeriod::where('warehouse_id', $data['warehouse_id'])
                ->where('year', $data['year'])->where('month', $data['month'])->lockForUpdate()->exists();
            if ($exists) {
                throw ValidationException::withMessages(['month' => ['Payroll already exists for this branch and month.']]);
            }
            $rows = $this->preview($data);
            $start = Carbon::create((int) $data['year'], (int) $data['month'], 1)->startOfMonth();
            $period = PayrollPeriod::create([
                'reference' => sprintf('PR-%04d%02d-%d', $data['year'], $data['month'], $data['warehouse_id']),
                'warehouse_id' => $data['warehouse_id'], 'year' => $data['year'], 'month' => $data['month'],
                'period_start' => $start->toDateString(), 'period_end' => $start->copy()->endOfMonth()->toDateString(),
                'commission_cutoff_date' => $data['commission_cutoff_date'],
                'payment_date' => $data['payment_date'] ?? null, 'note' => $data['note'] ?? null,
                'status' => 'draft', 'generated_by' => $userId, 'generated_at' => now(),
            ]);
            foreach ($rows as $row) {
                $ids = $row['commission_entry_ids'];
                unset($row['employee'], $row['role'], $row['branch'], $row['commission_entry_ids']);
                $row['payroll_period_id'] = $period->id; $row['paid_amount'] = 0;
                $row['remaining_amount'] = $row['net_payable'];
                $item = PayrollItem::create($row);
                foreach ($ids as $entryId) {
                    $entry = EmployeeCommissionEntry::whereKey($entryId)->where('status', 'accrued')
                        ->whereDoesntHave('payrollLink')->lockForUpdate()->first();
                    if (! $entry) {
                        throw ValidationException::withMessages(['commission' => ['A commission entry was already included in another payroll.']]);
                    }
                    PayrollCommissionLink::create(['payroll_item_id' => $item->id, 'commission_entry_id' => $entry->id, 'amount' => $entry->commission_amount]);
                    $entry->update(['status' => 'included_in_payroll']);
                }
                $this->snapshotComponents($item, $userId);
            }
            return $period->load(['warehouse', 'items.employee', 'items.designation']);
        }, 5);
    }

    public function approve(PayrollPeriod $period, int $userId): PayrollPeriod
    {
        return DB::transaction(function () use ($period, $userId) {
            $locked = PayrollPeriod::lockForUpdate()->findOrFail($period->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Only a draft payroll can be approved.']]);
            }
            $locked->update(['status' => 'approved_unpaid', 'approved_by' => $userId, 'approved_at' => now()]);
            $locked->items()->where('status', 'draft')->update(['status' => 'approved_unpaid']);
            return $locked->fresh('items');
        }, 5);
    }

    public function pay(PayrollItem $item, array $data, int $userId): PayrollItem
    {
        return DB::transaction(function () use ($item, $data, $userId) {
            $locked = PayrollItem::with('period')->lockForUpdate()->findOrFail($item->id);
            if (! in_array($locked->status, ['approved_unpaid', 'partially_paid'], true)) {
                throw ValidationException::withMessages(['status' => ['This payroll item is not approved for payment.']]);
            }
            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0 || $amount > (float) $locked->remaining_amount + 0.001) {
                throw ValidationException::withMessages(['amount' => ['Payment must be positive and cannot exceed the remaining amount.']]);
            }
            $method = PaymentMethod::findOrFail($data['payment_method_id']);
            $resolved = app(PaymentAccountService::class)->resolve($method, $data['account_id'] ?? null);
            $account = $resolved ? Account::lockForUpdate()->findOrFail($resolved->id) : null;
            $payment = PayrollPayment::create([
                'reference' => 'PP-'.$locked->id.'-'.str_pad((string) ($locked->payments()->count() + 1), 3, '0', STR_PAD_LEFT),
                'payroll_item_id' => $locked->id, 'account_id' => $account?->id,
                'payment_method_id' => $method->id, 'amount' => $amount,
                'payment_date' => $data['payment_date'], 'transaction_reference' => $data['transaction_reference'] ?? null,
                'note' => $data['note'] ?? null, 'processed_by' => $userId, 'processed_at' => now(),
            ]);
            if ($account) {
                $account->decrement('balance', $amount);
            }
            $paid = round((float) $locked->paid_amount + $amount, 2);
            $remaining = max(0, round((float) $locked->net_payable - $paid, 2));
            $status = $remaining <= 0 ? 'paid' : 'partially_paid';
            $locked->update(['paid_amount' => $paid, 'remaining_amount' => $remaining, 'status' => $status]);
            if ($status === 'paid') {
                EmployeeCommissionEntry::whereIn('id', $locked->commissionLinks()->pluck('commission_entry_id'))->update(['status' => 'paid']);
            }
            $this->refreshPeriodStatus($locked->period);
            return $locked->fresh(['payments.paymentMethod', 'payments.account']);
        }, 5);
    }

    private function snapshotComponents(PayrollItem $item, int $userId): void
    {
        foreach ([
            'basic_salary' => 'Basic salary', 'commission' => 'Commission', 'bonus' => 'Bonus',
            'allowances' => 'Allowances', 'overtime' => 'Overtime', 'absence_deduction' => 'Absence deduction',
            'deductions' => 'Other deductions', 'salary_advance' => 'Salary advance',
            'loan_recovery' => 'Loan recovery', 'commission_adjustments' => 'Commission reversal',
        ] as $type => $label) {
            PayrollItemComponent::create(['payroll_item_id' => $item->id, 'type' => $type, 'label' => $label, 'amount' => $item->{$type}, 'created_by' => $userId]);
        }
    }

    private function refreshPeriodStatus(PayrollPeriod $period): void
    {
        $statuses = $period->items()->pluck('status');
        $status = $statuses->every(fn ($s) => $s === 'paid') ? 'paid'
            : ($statuses->contains('paid') || $statuses->contains('partially_paid') ? 'partially_paid' : 'approved_unpaid');
        $period->update(['status' => $status]);
    }
}
