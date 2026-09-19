<?php

namespace App\Http\Controllers\hrm;

use App\Http\Controllers\Controller;
use App\Models\{Account, Designation, Employee, EmployeeBranchAssignment, EmployeeCommissionEntry, EmployeeSalaryHistory, PaymentMethod, Payroll, PayrollItem, PayrollPeriod, RoleCommissionRuleVersion, UserWarehouse, Warehouse};
use App\Services\MonthlyPayrollService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollManagementController extends Controller
{
    public function meta(Request $request)
    {
        $this->requirePermission($request, 'payroll_salary_view', true);
        $ids = $this->allowedWarehouseIds($request);
        return response()->json([
            'warehouses' => Warehouse::whereNull('deleted_at')->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::whereNull('deleted_at')->orderBy('username')->get(['id', 'username', 'designation_id', 'joining_date', 'leaving_date']),
            'designations' => Designation::whereNull('deleted_at')->orderBy('designation')->get(['id', 'designation']),
            'accounts' => Account::whereNull('deleted_at')->orderBy('account_name')->get(['id', 'account_name', 'account_type', 'balance']),
            'payment_methods' => PaymentMethod::whereNull('deleted_at')->get(['id', 'name']),
            'permissions' => $request->user('api')->effectivePermissionNames(),
        ]);
    }

    public function salaries(Request $request)
    {
        $this->requirePermission($request, 'payroll_salary_view', true);
        $date = $request->input('date', now()->toDateString());
        $query = EmployeeBranchAssignment::with(['employee', 'warehouse', 'designation'])->effectiveAt($date)
            ->when($this->allowedWarehouseIds($request), fn ($q, $ids) => $q->whereIn('warehouse_id', $ids))
            ->when($request->warehouse_id, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->search, fn ($q, $s) => $q->whereHas('employee', fn ($e) => $e->where('username', 'like', '%'.$s.'%')))
            ->orderBy('warehouse_id')->orderBy('employee_id');
        $page = $query->paginate((int) ($request->limit ?: 20));
        $page->getCollection()->transform(function ($assignment) use ($date) {
            $salary = EmployeeSalaryHistory::effectiveAt($date)->where('employee_id', $assignment->employee_id)->latest('effective_from')->first();
            return [
                'employee_id' => $assignment->employee_id, 'employee' => $assignment->employee->username,
                'employee_code' => 'EMP-'.str_pad((string) $assignment->employee_id, 5, '0', STR_PAD_LEFT),
                'warehouse_id' => $assignment->warehouse_id, 'branch' => $assignment->warehouse->name,
                'designation_id' => $assignment->designation_id, 'role' => $assignment->designation->designation,
                'basic_salary' => (float) ($salary?->monthly_salary ?? 0),
                'effective_from' => $salary?->effective_from?->toDateString(), 'effective_to' => $salary?->effective_to?->toDateString(),
                'employment_status' => $assignment->employee->leaving_date && Carbon::parse($assignment->employee->leaving_date)->lt($date) ? 'inactive' : 'active',
            ];
        });
        return response()->json($page);
    }

    public function storeSalary(Request $request)
    {
        $this->requirePermission($request, 'payroll_salary_manage');
        $data = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id', 'warehouse_id' => 'required|integer|exists:warehouses,id',
            'designation_id' => 'required|integer|exists:designations,id', 'monthly_salary' => 'required|numeric|min:0',
            'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from', 'is_active' => 'required|boolean',
        ]);
        $this->guardBranch($request, $data['warehouse_id']);
        DB::transaction(function () use ($data, $request) {
            $from = Carbon::parse($data['effective_from']); $to = $data['effective_to'] ?? null;
            $overlap = EmployeeSalaryHistory::where('employee_id', $data['employee_id'])
                ->where('effective_from', '<=', $to ?: '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
                ->lockForUpdate()->get();
            foreach ($overlap as $row) {
                if (Carbon::parse($row->effective_from)->lt($from)) {
                    $row->update(['effective_to' => $from->copy()->subDay()->toDateString(), 'updated_by' => $request->user('api')->id]);
                } else {
                    throw ValidationException::withMessages(['effective_from' => ['Salary effective periods cannot overlap.']]);
                }
            }
            EmployeeSalaryHistory::create($data + ['created_by' => $request->user('api')->id, 'updated_by' => $request->user('api')->id]);
            $this->setAssignment($data, $request->user('api')->id);
        }, 5);
        return response()->json(['success' => true]);
    }

    public function commissionRules(Request $request)
    {
        $this->requirePermission($request, 'payroll_commission_rules_view');
        $date = $request->input('date', now()->toDateString());
        $rules = RoleCommissionRuleVersion::effectiveAt($date)->orderByDesc('effective_from')->orderByDesc('version')->get()->unique('designation_id')->keyBy('designation_id');
        return Designation::whereNull('deleted_at')->orderBy('designation')->get(['id', 'designation'])->map(fn ($role) => [
            'designation_id' => $role->id, 'role' => $role->designation,
            'almadina_percentage' => (float) ($rules->get($role->id)?->almadina_percentage ?? 0),
            'wholesale_percentage' => (float) ($rules->get($role->id)?->wholesale_percentage ?? 0),
            'minimum_percentage' => (float) ($rules->get($role->id)?->minimum_percentage ?? 0),
            'is_active' => (bool) ($rules->get($role->id)?->is_active ?? false),
            'effective_from' => $rules->get($role->id)?->effective_from?->toDateString(), 'version' => $rules->get($role->id)?->version,
        ]);
    }

    public function commissionImpact(Request $request)
    {
        $this->requirePermission($request, 'payroll_commission_rules_view');
        $data = $request->validate([
            'rules' => 'required|array',
            'rules.*.designation_id' => 'required|integer',
            'rules.*.almadina_percentage' => 'nullable|numeric|min:0',
            'rules.*.wholesale_percentage' => 'nullable|numeric|min:0',
            'rules.*.minimum_percentage' => 'nullable|numeric|min:0',
        ]);
        $rules = collect($data['rules'])->map(function ($rule) {
            foreach (['almadina_percentage', 'wholesale_percentage', 'minimum_percentage'] as $field) {
                $rule[$field] = (float) ($rule[$field] ?? 0);
            }

            return $rule;
        })->all();

        return response()->json($this->impact($rules));
    }

    public function saveCommissionRules(Request $request)
    {
        $this->requirePermission($request, 'payroll_commission_rules_manage');
        $data = $request->validate([
            'effective_from' => 'required|date', 'confirm_overallocation' => 'nullable|boolean', 'rules' => 'required|array',
            'rules.*.designation_id' => 'required|integer|exists:designations,id',
            'rules.*.almadina_percentage' => 'required|numeric|min:0', 'rules.*.wholesale_percentage' => 'required|numeric|min:0',
            'rules.*.minimum_percentage' => 'required|numeric|min:0', 'rules.*.is_active' => 'required|boolean',
        ]);
        $impact = $this->impact($data['rules']);
        if ($impact['exceeds_100'] && ! ($data['confirm_overallocation'] ?? false)) {
            throw ValidationException::withMessages(['confirm_overallocation' => ['Combined employee allocation exceeds 100%. Review the impact preview and explicitly confirm.']]);
        }
        DB::transaction(function () use ($data, $request, $impact) {
            $from = Carbon::parse($data['effective_from']);
            foreach ($data['rules'] as $rule) {
                $open = RoleCommissionRuleVersion::where('designation_id', $rule['designation_id'])
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
                    ->lockForUpdate()->get();
                foreach ($open as $old) {
                    if (Carbon::parse($old->effective_from)->gte($from)) throw ValidationException::withMessages(['effective_from' => ['A rule version already starts on or after this date.']]);
                    $old->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
                }
                $version = (int) RoleCommissionRuleVersion::where('designation_id', $rule['designation_id'])->max('version') + 1;
                RoleCommissionRuleVersion::create($rule + ['version' => $version, 'effective_from' => $from->toDateString(), 'overallocation_confirmed' => $impact['exceeds_100'], 'created_by' => $request->user('api')->id]);
            }
        }, 5);
        return response()->json(['success' => true, 'impact' => $impact]);
    }

    public function ledger(Request $request)
    {
        $this->requirePermission($request, 'payroll_commission_ledger_view');
        $query = EmployeeCommissionEntry::with(['sale:id,Ref', 'warehouse:id,name', 'employee:id,username', 'designation:id,designation', 'product:id,name', 'payrollLink.item.period'])
            ->when($this->allowedWarehouseIds($request), fn ($q, $ids) => $q->whereIn('warehouse_id', $ids))
            ->when($request->warehouse_id, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->employee_id, fn ($q, $v) => $q->where('employee_id', $v))
            ->when($request->designation_id, fn ($q, $v) => $q->where('designation_id', $v))
            ->when($request->price_type, fn ($q, $v) => $q->where('price_type', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->date_from, fn ($q, $v) => $q->whereDate('commission_date', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->whereDate('commission_date', '<=', $v))
            ->when($request->search, fn ($q, $v) => $q->whereHas('sale', fn ($s) => $s->where('Ref', 'like', '%'.$v.'%')))
            ->latest('commission_date')->latest('id');
        $summaryQuery = clone $query;
        $summary = [
            'today' => (float) (clone $summaryQuery)->whereDate('commission_date', today())->sum('commission_amount'),
            'current_month' => (float) (clone $summaryQuery)->whereYear('commission_date', now()->year)->whereMonth('commission_date', now()->month)->sum('commission_amount'),
            'accrued' => (float) (clone $summaryQuery)->where('status', 'accrued')->sum('commission_amount'),
            'included' => (float) (clone $summaryQuery)->where('status', 'included_in_payroll')->sum('commission_amount'),
            'paid' => (float) (clone $summaryQuery)->where('status', 'paid')->sum('commission_amount'),
            'reversed' => abs((float) (clone $summaryQuery)->where('entry_type', 'reversal')->sum('commission_amount')),
        ];
        return response()->json(['entries' => $query->paginate((int) ($request->limit ?: 20)), 'summary' => $summary]);
    }

    public function periods(Request $request)
    {
        $this->requirePermission($request, 'payroll_salary_view', true);
        $query = PayrollPeriod::with(['warehouse:id,name', 'items.employee:id,username', 'items.designation:id,designation'])
            ->withSum('items', 'basic_salary')->withSum('items', 'commission')->withSum('items', 'bonus')
            ->withSum('items', 'allowances')->withSum('items', 'deductions')->withSum('items', 'net_payable')
            ->withSum('items', 'paid_amount')->withSum('items', 'remaining_amount')->withCount('items')
            ->when($this->allowedWarehouseIds($request), fn ($q, $ids) => $q->whereIn('warehouse_id', $ids))
            ->when($request->warehouse_id, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->year, fn ($q, $v) => $q->where('year', $v))
            ->when($request->month, fn ($q, $v) => $q->where('month', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))->latest('year')->latest('month');
        return response()->json([
            'periods' => $query->paginate((int) ($request->limit ?: 20)),
            'legacy_payrolls' => Payroll::with('employee:id,username')->whereNull('deleted_at')->latest()->get(),
        ]);
    }

    public function preview(Request $request, MonthlyPayrollService $service)
    {
        $this->requirePermission($request, 'payroll_generate');
        $data = $this->payrollData($request); $this->guardBranch($request, $data['warehouse_id']);
        return response()->json(['items' => $service->preview($data)]);
    }

    public function generate(Request $request, MonthlyPayrollService $service)
    {
        $this->requirePermission($request, 'payroll_generate');
        $data = $this->payrollData($request); $this->guardBranch($request, $data['warehouse_id']);
        return response()->json(['success' => true, 'period' => $service->generate($data, $request->user('api')->id)]);
    }

    public function approve(Request $request, PayrollPeriod $period, MonthlyPayrollService $service)
    {
        $this->requirePermission($request, 'payroll_approve'); $this->guardBranch($request, $period->warehouse_id);
        return response()->json(['success' => true, 'period' => $service->approve($period, $request->user('api')->id)]);
    }

    public function detail(Request $request, PayrollItem $item)
    {
        $this->requirePermission($request, 'payroll_salary_view', true); $this->guardBranch($request, $item->warehouse_id);
        return $item->load(['period.warehouse', 'employee', 'designation', 'components', 'payments.paymentMethod', 'payments.account', 'commissionLinks.entry.sale', 'commissionLinks.entry.product']);
    }

    public function pay(Request $request, PayrollItem $item, MonthlyPayrollService $service)
    {
        $this->requirePermission($request, 'payroll_pay'); $this->guardBranch($request, $item->warehouse_id);
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01', 'payment_date' => 'required|date',
            'payment_method_id' => 'required|integer|exists:payment_methods,id',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'transaction_reference' => 'nullable|string|max:120', 'note' => 'nullable|string|max:1000',
        ]);
        return response()->json(['success' => true, 'item' => $service->pay($item, $data, $request->user('api')->id)]);
    }

    public function payslip(Request $request, PayrollItem $item)
    {
        $this->requirePermission($request, 'payroll_payslip_print'); $this->guardBranch($request, $item->warehouse_id);
        $item->load(['period.warehouse', 'employee.company', 'designation', 'components', 'payments.paymentMethod', 'payments.account', 'commissionLinks.entry.sale', 'commissionLinks.entry.product']);
        return Pdf::loadView('pdf.payroll_payslip', compact('item'))->download('payslip-'.$item->period->reference.'-'.$item->employee_id.'.pdf');
    }

    private function payrollData(Request $request): array
    {
        return $request->validate([
            'month' => 'required|integer|between:1,12', 'year' => 'required|integer|between:2000,2200',
            'warehouse_id' => 'required|integer|exists:warehouses,id', 'commission_cutoff_date' => 'required|date',
            'payment_date' => 'nullable|date', 'note' => 'nullable|string|max:1000', 'items' => 'nullable|array',
            'items.*.employee_id' => 'required|integer', 'items.*.bonus' => 'nullable|numeric|min:0',
            'items.*.allowances' => 'nullable|numeric|min:0', 'items.*.overtime' => 'nullable|numeric|min:0',
            'items.*.absence_deduction' => 'nullable|numeric|min:0', 'items.*.deductions' => 'nullable|numeric|min:0',
            'items.*.salary_advance' => 'nullable|numeric|min:0', 'items.*.loan_recovery' => 'nullable|numeric|min:0',
        ]);
    }

    private function setAssignment(array $data, int $userId): void
    {
        $from = Carbon::parse($data['effective_from']);
        $open = EmployeeBranchAssignment::where('employee_id', $data['employee_id'])
            ->where('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()))
            ->lockForUpdate()->get();
        foreach ($open as $row) {
            if ((int) $row->warehouse_id === (int) $data['warehouse_id']
                && (int) $row->designation_id === (int) $data['designation_id']) return;
            if (Carbon::parse($row->effective_from)->gte($from)) {
                throw ValidationException::withMessages(['effective_from' => ['Employee branch assignments cannot overlap.']]);
            }
            $row->update(['effective_to' => $from->copy()->subDay()->toDateString(), 'updated_by' => $userId]);
        }
        EmployeeBranchAssignment::create([
            'employee_id' => $data['employee_id'], 'warehouse_id' => $data['warehouse_id'],
            'designation_id' => $data['designation_id'], 'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null, 'is_active' => $data['is_active'],
            'created_by' => $userId, 'updated_by' => $userId,
        ]);
    }

    private function impact(array $rules): array
    {
        $rates = collect($rules)->keyBy('designation_id');
        $counts = EmployeeBranchAssignment::selectRaw('warehouse_id, designation_id, COUNT(DISTINCT employee_id) employee_count')
            ->effectiveAt(now()->toDateString())->groupBy('warehouse_id', 'designation_id')->get();
        $branches = Warehouse::whereNull('deleted_at')->get(['id', 'name'])->map(function ($branch) use ($counts, $rates) {
            $rows = $counts->where('warehouse_id', $branch->id)->map(function ($count) use ($rates) {
                $rate = $rates->get($count->designation_id, []);
                return [
                    'designation_id' => $count->designation_id, 'employee_count' => (int) $count->employee_count,
                    'almadina_combined' => (float) $count->employee_count * (float) ($rate['almadina_percentage'] ?? 0),
                    'wholesale_combined' => (float) $count->employee_count * (float) ($rate['wholesale_percentage'] ?? 0),
                    'minimum_combined' => (float) $count->employee_count * (float) ($rate['minimum_percentage'] ?? 0),
                ];
            })->values();
            return [
                'warehouse_id' => $branch->id, 'branch' => $branch->name, 'roles' => $rows,
                'almadina_total' => $rows->sum('almadina_combined'),
                'wholesale_total' => $rows->sum('wholesale_combined'),
                'minimum_total' => $rows->sum('minimum_combined'),
            ];
        });
        return [
            'branches' => $branches,
            'exceeds_100' => $branches->contains(fn ($b) => max($b['almadina_total'], $b['wholesale_total'], $b['minimum_total']) > 100),
        ];
    }

    private function requirePermission(Request $request, string $permission, bool $legacyPayroll = false): void
    {
        $user = $request->user('api');
        $allowed = $user->isSuperAdmin() || $user->effectivePermissionNames()->contains($permission)
            || ($legacyPayroll && $user->effectivePermissionNames()->contains('payroll'));
        abort_unless($allowed, 403, 'You are not authorized for this payroll operation.');
    }

    private function allowedWarehouseIds(Request $request): ?array
    {
        $user = $request->user('api');
        if ($user->isSuperAdmin() || $user->is_all_warehouses || $user->effectivePermissionNames()->contains('payroll_all_branches')) return null;
        return UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id')->map(fn ($id) => (int) $id)->all();
    }

    private function guardBranch(Request $request, int $warehouseId): void
    {
        $ids = $this->allowedWarehouseIds($request);
        abort_if($ids !== null && ! in_array($warehouseId, $ids, true), 403, 'You are not authorized for this branch.');
    }
}
