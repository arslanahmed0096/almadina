<?php

namespace App\Services\Tax;

use App\Models\Tax;
use Illuminate\Support\Collection;

class TaxCalculationService
{
    /** Fixed taxes are applied once per invoice line, not per unit. */
    public function calculateLine(string|float $unitPrice, string|float $quantity, iterable $taxes): array
    {
        $base = Decimal::mul($unitPrice, $quantity);
        $priorAdditive = '0.000000';
        $rows = [];

        foreach (collect($taxes)->sortBy([['priority', 'asc'], ['id', 'asc']]) as $tax) {
            $tax = $tax instanceof Tax ? $tax : (object) $tax;
            $taxableBase = ! empty($tax->is_compound) ? Decimal::add($base, $priorAdditive) : $base;
            if ($tax->calculation_type === 'fixed') {
                $amount = Decimal::round($tax->rate, 6);
            } elseif ($tax->behavior === 'inclusive') {
                $amount = Decimal::mul($taxableBase, Decimal::div($tax->rate, Decimal::add('100', $tax->rate)), 12);
            } else {
                $amount = Decimal::mul($taxableBase, Decimal::div($tax->rate, '100'), 12);
            }
            $amount = Decimal::round($amount, 6);
            if ($tax->behavior === 'additive') $priorAdditive = Decimal::add($priorAdditive, $amount);
            $rows[] = [
                'tax_id' => $tax->id ?? null, 'tax_name' => $tax->name, 'tax_code' => $tax->code,
                'calculation_type' => $tax->calculation_type, 'rate' => Decimal::round($tax->rate, 6),
                'behavior' => $tax->behavior, 'taxable_base' => $taxableBase, 'tax_amount' => $amount,
                'priority' => (int) $tax->priority, 'is_compound' => (bool) $tax->is_compound,
            ];
        }

        return $rows;
    }

    public function totals(string|float $subtotal, string|float $discount, string|float $shipping, Collection|array $taxRows): array
    {
        $rows = collect($taxRows);
        $additive = $rows->where('behavior', 'additive')->reduce(fn ($sum, $row) => Decimal::add($sum, $row['tax_amount']), '0');
        $deductive = $rows->where('behavior', 'deductive')->reduce(fn ($sum, $row) => Decimal::add($sum, $row['tax_amount']), '0');
        $inclusive = $rows->where('behavior', 'inclusive')->reduce(fn ($sum, $row) => Decimal::add($sum, $row['tax_amount']), '0');
        $grand = Decimal::add(Decimal::sub(Decimal::add(Decimal::sub($subtotal, $discount), $additive), $deductive), $shipping);

        return compact('subtotal', 'additive', 'deductive', 'inclusive', 'discount', 'shipping') + ['grand_total' => Decimal::round($grand, 2)];
    }
}
