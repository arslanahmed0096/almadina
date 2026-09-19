<?php

namespace App\Services;

use App\Models\EmployeeBranchAssignment;
use App\Models\EmployeeCommissionEntry;
use App\Models\PayrollCommissionLink;
use App\Models\PayrollItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RoleCommissionRuleVersion;
use App\Models\Sale;
use App\Models\SaleReturn;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BranchCommissionService
{
    private const PRICE_TYPES = [
        'retail' => ['almadina', 2, 'almadina_percentage'],
        'price' => ['almadina', 2, 'almadina_percentage'],
        'almadina' => ['almadina', 2, 'almadina_percentage'],
        'al_madina' => ['almadina', 2, 'almadina_percentage'],
        'wholesale' => ['wholesale', 1, 'wholesale_percentage'],
        'wholesale_price' => ['wholesale', 1, 'wholesale_percentage'],
        'minimum' => ['minimum', 0, 'minimum_percentage'],
        'min' => ['minimum', 0, 'minimum_percentage'],
        'min_price' => ['minimum', 0, 'minimum_percentage'],
    ];

    public function generateForCompletedSale(Sale $sale): array
    {
        if ($sale->statut !== 'completed') {
            return [];
        }

        return DB::transaction(function () use ($sale) {
            $locked = Sale::with(['details.product', 'details.productVariant'])
                ->whereNull('deleted_at')->lockForUpdate()->findOrFail($sale->id);
            if ($locked->statut !== 'completed') {
                return [];
            }
            if (EmployeeCommissionEntry::where('sale_id', $locked->id)->where('entry_type', 'accrual')->exists()) {
                return [];
            }

            $date = Carbon::parse($locked->date)->toDateString();
            $assignments = EmployeeBranchAssignment::with('employee')
                ->effectiveAt($date)->where('warehouse_id', $locked->warehouse_id)
                ->whereHas('employee', function ($q) use ($date) {
                    $q->whereNull('deleted_at')
                        ->where(fn ($e) => $e->whereNull('joining_date')->orWhere('joining_date', '<=', $date))
                        ->where(fn ($e) => $e->whereNull('leaving_date')->orWhere('leaving_date', '>=', $date));
                })->get();

            $rules = RoleCommissionRuleVersion::effectiveAt($date)->where('is_active', true)
                ->whereIn('designation_id', $assignments->pluck('designation_id')->unique())
                ->orderByDesc('effective_from')->orderByDesc('version')->get()
                ->unique('designation_id')->keyBy('designation_id');

            $created = [];
            foreach ($locked->details as $line) {
                $price = $this->priceConfiguration($line->price_type);
                if (! $price) {
                    continue;
                }
                $profit = $this->storedProfit($line->productVariant ?: $line->product, $price[1]);
                $quantity = (string) $line->quantity;
                $totalProfit = bcmul((string) $profit, $quantity, 4);
                foreach ($assignments as $assignment) {
                    $rule = $rules->get($assignment->designation_id);
                    $percentage = $rule ? (string) $rule->{$price[2]} : '0';
                    if (! $rule || bccomp($percentage, '0', 4) <= 0) {
                        continue;
                    }
                    $amount = bcdiv(bcmul($totalProfit, $percentage, 8), '100', 4);
                    $eventKey = 'sale:'.$line->id.':employee:'.$assignment->employee_id;
                    $entry = EmployeeCommissionEntry::firstOrCreate(['event_key' => $eventKey], [
                        'entry_type' => 'accrual', 'sale_id' => $locked->id, 'sale_detail_id' => $line->id,
                        'warehouse_id' => $locked->warehouse_id, 'employee_id' => $assignment->employee_id,
                        'designation_id' => $assignment->designation_id, 'product_id' => $line->product_id,
                        'product_variant_id' => $line->product_variant_id, 'rule_version_id' => $rule->id,
                        'price_type' => $price[0], 'stored_profit_per_unit' => $profit, 'quantity' => $quantity,
                        'total_applicable_profit' => $totalProfit, 'commission_percentage' => $percentage,
                        'commission_amount' => $amount, 'commission_date' => $date, 'status' => 'accrued',
                        'created_by' => Auth::id(),
                    ]);
                    if ($entry->wasRecentlyCreated) {
                        $created[] = $entry;
                    }
                }
            }
            return $created;
        }, 5);
    }

    public function reverseForReturn(SaleReturn $return): array
    {
        if ($return->statut !== 'received') {
            return [];
        }
        return DB::transaction(function () use ($return) {
            $locked = SaleReturn::with('details')->lockForUpdate()->findOrFail($return->id);
            $created = [];
            foreach ($locked->details as $detail) {
                $originals = EmployeeCommissionEntry::where('entry_type', 'accrual')
                    ->where('sale_detail_id', $detail->sale_detail_id)->lockForUpdate()->get();
                foreach ($originals as $original) {
                    $created[] = $this->createReversal(
                        $original, (string) $detail->quantity,
                        'return:'.$detail->id.':commission:'.$original->id.':qty:'.number_format((float) $detail->quantity, 4, '.', ''),
                        $locked->id, $detail->id, 'Sale return '.$locked->Ref
                    );
                }
            }
            return array_values(array_filter($created));
        }, 5);
    }

    public function reverseForCancellation(Sale $sale): array
    {
        return DB::transaction(function () use ($sale) {
            $created = [];
            $originals = EmployeeCommissionEntry::where('entry_type', 'accrual')
                ->where('sale_id', $sale->id)->lockForUpdate()->get();
            foreach ($originals as $original) {
                $created[] = $this->createReversal(
                    $original, (string) $original->quantity,
                    'cancel:'.$sale->id.':commission:'.$original->id,
                    null, null, 'Sale cancelled '.$sale->Ref
                );
            }
            return array_values(array_filter($created));
        }, 5);
    }

    public function storedProfit($product, int $marginIndex): string
    {
        $margins = $product?->pricing_margins ?: [];
        $row = array_values($margins)[$marginIndex] ?? null;
        return number_format(max(0, (float) ($row['profit'] ?? 0)), 4, '.', '');
    }

    public function normalizePriceType(?string $priceType): ?string
    {
        return $this->priceConfiguration($priceType)[0] ?? null;
    }

    private function priceConfiguration(?string $priceType): ?array
    {
        return self::PRICE_TYPES[strtolower(trim((string) $priceType))] ?? null;
    }

    private function createReversal(
        EmployeeCommissionEntry $original,
        string $requestedQuantity,
        string $eventKey,
        ?int $returnId,
        ?int $returnDetailId,
        string $reason
    ): ?EmployeeCommissionEntry {
        if (EmployeeCommissionEntry::where('event_key', $eventKey)->exists()) {
            return null;
        }
        $already = (string) abs((float) EmployeeCommissionEntry::where('original_entry_id', $original->id)
            ->where('entry_type', 'reversal')->sum('quantity'));
        $remaining = bcsub((string) $original->quantity, $already, 4);
        $quantity = bccomp($requestedQuantity, $remaining, 4) > 0 ? $remaining : $requestedQuantity;
        if (bccomp($quantity, '0', 4) <= 0) {
            return null;
        }
        $profit = bcmul((string) $original->stored_profit_per_unit, $quantity, 4);
        $amount = bcmul('-1', bcdiv(bcmul($profit, (string) $original->commission_percentage, 8), '100', 4), 4);
        $entry = EmployeeCommissionEntry::create([
            'event_key' => $eventKey, 'entry_type' => 'reversal', 'original_entry_id' => $original->id,
            'sale_id' => $original->sale_id, 'sale_detail_id' => $original->sale_detail_id,
            'sale_return_id' => $returnId, 'sale_return_detail_id' => $returnDetailId,
            'warehouse_id' => $original->warehouse_id, 'employee_id' => $original->employee_id,
            'designation_id' => $original->designation_id, 'product_id' => $original->product_id,
            'product_variant_id' => $original->product_variant_id, 'rule_version_id' => $original->rule_version_id,
            'price_type' => $original->price_type, 'stored_profit_per_unit' => $original->stored_profit_per_unit,
            'quantity' => bcmul('-1', $quantity, 4), 'total_applicable_profit' => bcmul('-1', $profit, 4),
            'commission_percentage' => $original->commission_percentage, 'commission_amount' => $amount,
            'commission_date' => now()->toDateString(), 'status' => 'accrued', 'reason' => $reason,
            'created_by' => Auth::id(),
        ]);

        $totalReversed = abs((float) EmployeeCommissionEntry::where('original_entry_id', $original->id)->sum('quantity'));
        $original->update(['status' => $totalReversed + 0.0001 >= (float) $original->quantity ? 'reversed' : 'partially_reversed']);
        $link = $original->payrollLink()->with('item')->first();
        if ($link && in_array($link->item->status, ['draft', 'approved_unpaid', 'partially_paid'], true)
            && (float) $link->item->paid_amount <= 0) {
            PayrollCommissionLink::create(['payroll_item_id' => $link->payroll_item_id, 'commission_entry_id' => $entry->id, 'amount' => $amount]);
            $entry->update(['status' => 'included_in_payroll']);
            $this->recalculateItem($link->item);
        }
        return $entry;
    }

    private function recalculateItem(PayrollItem $item): void
    {
        $commission = (float) $item->commissionLinks()->sum('amount');
        $positive = max(0, $commission);
        $adjustments = min(0, $commission);
        $gross = (float) $item->basic_salary + $positive + (float) $item->bonus + (float) $item->allowances + (float) $item->overtime;
        $deductions = (float) $item->absence_deduction + (float) $item->deductions + (float) $item->salary_advance + (float) $item->loan_recovery + abs($adjustments);
        $net = max(0, $gross - $deductions);
        $item->update([
            'commission' => $positive, 'commission_adjustments' => $adjustments,
            'gross_salary' => $gross, 'total_deductions' => $deductions,
            'net_payable' => $net, 'remaining_amount' => max(0, $net - (float) $item->paid_amount),
        ]);
    }
}
