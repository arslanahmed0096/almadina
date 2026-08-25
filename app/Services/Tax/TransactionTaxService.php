<?php

namespace App\Services\Tax;

use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnDetails;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetails;
use App\Models\TaxPriceType;
use App\Models\TaxAudit;
use App\Models\TransactionTaxSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionTaxService
{
    public function __construct(private TaxResolver $resolver, private TaxCalculationService $calculator) {}

    public function snapshotPurchase(Purchase $purchase, array $inputLines, ?User $user): Collection
    {
        $details = PurchaseDetail::where('purchase_id', $purchase->id)->orderBy('id')->get();
        $snapshots = $this->replaceSnapshots('purchase', $purchase->id, $purchase->warehouse_id, $details, $inputLines, $user);
        $subtotal = '0';
        foreach ($details as $detail) {
            $rb = (string) ($detail->company_rb_price ?: $detail->cost);
            $discount = (string) ($detail->discount ?? 0);
            $discountAmount = (string) $detail->discount_method === '2' ? $discount : Decimal::mul($rb, Decimal::div($discount, '100'));
            $netLine = Decimal::mul(Decimal::sub($rb, $discountAmount), (string) $detail->quantity);
            $lineTaxes = $snapshots->where('transaction_line_id', $detail->id);
            $lineAdditive = $lineTaxes->where('behavior', 'additive')->reduce(fn ($sum, $row) => Decimal::add($sum, (string) $row->tax_amount), '0');
            $lineDeductive = $lineTaxes->where('behavior', 'deductive')->reduce(fn ($sum, $row) => Decimal::add($sum, (string) $row->tax_amount), '0');
            $detail->update([
                'sales_tax' => Decimal::round((string) $lineAdditive, 2),
                'withholding_tax' => Decimal::round((string) $lineDeductive, 2),
                'total' => Decimal::round(Decimal::sub(Decimal::add($netLine, (string) $lineAdditive), (string) $lineDeductive), 2),
            ]);
            $subtotal = Decimal::add($subtotal, $netLine);
        }
        $taxRows = $snapshots->map(fn ($snapshot) => ['behavior' => $snapshot->behavior, 'tax_amount' => $snapshot->tax_amount]);
        $totals = $this->calculator->totals($subtotal, (string) $purchase->discount, (string) $purchase->shipping, $taxRows);
        $purchase->update([
            'TaxNet' => Decimal::round($totals['additive'], 2),
            'withholding_tax' => Decimal::round($totals['deductive'], 2),
            'GrandTotal' => $totals['grand_total'],
        ]);
        return $snapshots;
    }

    public function snapshotSale(Sale $sale, array $inputLines, ?User $user): Collection
    {
        $type = $sale->is_pos ? 'pos' : 'sale_invoice';
        $details = SaleDetail::where('sale_id', $sale->id)->orderBy('id')->get();
        $snapshots = $this->replaceSnapshots($type, $sale->id, $sale->warehouse_id, $details, $inputLines, $user);

        // Keep legacy transactions unchanged when no managed tax applies. Once a
        // managed tax applies, its centralized calculation becomes authoritative
        // for both the saved line totals and invoice totals.
        if ($snapshots->isEmpty()) return $snapshots;

        $subtotal = '0.000000';
        foreach ($details->values() as $index => $detail) {
            $input = $inputLines[$index] ?? [];
            $base = collect($this->linePriceBases($type, $detail, $input))->first() ?? '0';
            $lineBase = Decimal::mul($base, $detail->quantity);
            $subtotal = Decimal::add($subtotal, $lineBase);
            $lineTaxes = $snapshots->where('transaction_line_id', $detail->id);
            $additive = $lineTaxes->where('behavior', 'additive')->reduce(fn ($sum, $tax) => Decimal::add($sum, $tax->tax_amount), '0');
            $deductive = $lineTaxes->where('behavior', 'deductive')->reduce(fn ($sum, $tax) => Decimal::add($sum, $tax->tax_amount), '0');
            $detail->update(['total' => Decimal::round(Decimal::sub(Decimal::add($lineBase, $additive), $deductive), 2)]);
        }

        $discount = Decimal::round($sale->discount ?? 0, 6);
        if ((string) ($sale->discount_Method ?? '2') === '1') {
            $discount = Decimal::mul($subtotal, Decimal::div($discount, '100'));
        }
        if (bccomp($discount, $subtotal, 6) === 1) $discount = $subtotal;
        $remaining = Decimal::sub($subtotal, $discount);
        $pointsDiscount = Decimal::round($sale->discount_from_points ?? 0, 6);
        if (bccomp($pointsDiscount, $remaining, 6) === 1) $pointsDiscount = $remaining;
        $discount = Decimal::add($discount, $pointsDiscount);

        $taxRows = $snapshots->map(fn ($snapshot) => ['behavior' => $snapshot->behavior, 'tax_amount' => $snapshot->tax_amount]);
        $totals = $this->calculator->totals($subtotal, $discount, (string) ($sale->shipping ?? 0), $taxRows);
        $sale->update([
            'tax_rate' => 0,
            'TaxNet' => Decimal::round($totals['additive'], 2),
            'GrandTotal' => $totals['grand_total'],
        ]);

        return $snapshots;
    }

    public function reverseSaleReturn(SaleReturn $return): Collection
    {
        if (! $return->sale_id) return collect();
        return $this->reverse(
            'sale_return', $return->id,
            $return->sale->is_pos ? 'pos' : 'sale_invoice', $return->sale_id,
            SaleDetail::where('sale_id', $return->sale_id)->get(),
            SaleReturnDetails::where('sale_return_id', $return->id)->get()
        );
    }

    public function reversePurchaseReturn(PurchaseReturn $return): Collection
    {
        if (! $return->purchase_id) return collect();
        return $this->reverse(
            'purchase_return', $return->id, 'purchase', $return->purchase_id,
            PurchaseDetail::where('purchase_id', $return->purchase_id)->get(),
            PurchaseReturnDetails::where('purchase_return_id', $return->id)->get()
        );
    }

    private function replaceSnapshots(string $type, int $transactionId, int $warehouseId, Collection $details, array $inputLines, ?User $user): Collection
    {
        return DB::transaction(function () use ($type, $transactionId, $warehouseId, $details, $inputLines, $user) {
            TransactionTaxSnapshot::where('transaction_type', $type)->where('transaction_id', $transactionId)->where('is_reversal', false)->delete();
            $created = collect();
            foreach ($details->values() as $index => $detail) {
                $input = $inputLines[$index] ?? [];
                foreach ($this->linePriceBases($type, $detail, $input) as $priceCode => $unitPrice) {
                    $priceType = TaxPriceType::where('code', $priceCode)->first();
                    if (! $priceType) continue;
                    $taxes = $this->resolver->applicable($type, $priceType->id, $warehouseId, $user);
                    if (isset($input['tax_ids']) && is_array($input['tax_ids'])) {
                        $selected = collect($input['tax_ids'])->map(fn ($id) => (int) $id);
                        $automaticIds = $taxes->pluck('id')->sort()->values();
                        if (! $user?->isSuperAdmin() && ! $user?->effectivePermissionNames()->contains('taxes.override')) {
                            if ($selected->sort()->values()->all() !== $automaticIds->all()) {
                                throw ValidationException::withMessages(['taxes' => ['You are not allowed to override automatically selected taxes.']]);
                            }
                        }
                        if ($selected->sort()->values()->all() !== $automaticIds->all()) {
                            TaxAudit::create([
                                'user_id' => $user?->id, 'event' => 'manual_tax_override',
                                'auditable_type' => $type, 'auditable_id' => $transactionId,
                                'before' => ['automatic_tax_ids' => $automaticIds->all(), 'line_id' => $detail->id],
                                'after' => ['selected_tax_ids' => $selected->sort()->values()->all(), 'line_id' => $detail->id],
                            ]);
                        }
                        $taxes = $taxes->whereIn('id', $selected);
                    }
                    foreach ($this->calculator->calculateLine($unitPrice, $detail->quantity, $taxes) as $row) {
                        $created->push(TransactionTaxSnapshot::create($row + [
                            'transaction_type' => $type, 'transaction_id' => $transactionId,
                            'transaction_line_id' => $detail->id, 'price_type_id' => $priceType->id,
                            'price_type_code' => $priceType->code, 'price_type_name' => $priceType->name,
                            'quantity' => $detail->quantity,
                        ]));
                    }
                }
            }
            return $created;
        });
    }

    private function linePriceBases(string $type, Model $detail, array $input): array
    {
        if ($type === 'purchase') {
            $rb = (string) ($detail->company_rb_price ?: $detail->cost);
            $discount = (string) ($detail->discount ?? 0);
            $discountAmount = (string) $detail->discount_method === '2' ? $discount : Decimal::mul($rb, Decimal::div($discount, '100'));
            return [
                'company_rb_price' => Decimal::sub($rb, $discountAmount),
                'mrp_price' => (string) ($detail->mrp_price ?: $detail->cost),
                'cost' => (string) $detail->cost,
            ];
        }

        $priceType = ($detail->price_type ?? ($input['price_type'] ?? 'retail')) === 'wholesale' ? 'wholesale_price' : 'price';
        $price = (string) $detail->price;
        $discount = (string) ($detail->discount ?? 0);
        $discountAmount = (string) $detail->discount_method === '2' ? $discount : Decimal::mul($price, Decimal::div($discount, '100'));
        return [$priceType => Decimal::sub($price, $discountAmount)];
    }

    private function reverse(string $returnType, int $returnId, string $sourceType, int $sourceId, Collection $sourceLines, Collection $returnLines): Collection
    {
        return DB::transaction(function () use ($returnType, $returnId, $sourceType, $sourceId, $sourceLines, $returnLines) {
            TransactionTaxSnapshot::where('transaction_type', $returnType)->where('transaction_id', $returnId)->delete();
            $created = collect();
            foreach ($returnLines as $returnLine) {
                $sourceLine = $sourceLines->first(fn ($line) => (int) $line->product_id === (int) $returnLine->product_id && (int) ($line->product_variant_id ?? 0) === (int) ($returnLine->product_variant_id ?? 0));
                $previouslyReturned = $sourceLine ? $this->previouslyReturnedQuantity($sourceType, $sourceId, $returnId, $returnLine) : '0';
                if (! $sourceLine || bccomp(Decimal::add($previouslyReturned, (string) $returnLine->quantity), (string) $sourceLine->quantity, 6) === 1) {
                    throw ValidationException::withMessages(['quantity' => ['Returned quantity exceeds the original quantity.']]);
                }
                $ratio = Decimal::div($returnLine->quantity, $sourceLine->quantity);
                $snapshots = TransactionTaxSnapshot::where('transaction_type', $sourceType)->where('transaction_id', $sourceId)->where('transaction_line_id', $sourceLine->id)->where('is_reversal', false)->get();
                foreach ($snapshots as $snapshot) {
                    $already = TransactionTaxSnapshot::where('reversal_of_id', $snapshot->id)->where('id', '<>', 0)->sum('tax_amount');
                    $amount = Decimal::round(Decimal::mul($snapshot->tax_amount, $ratio), 6);
                    if (bccomp(Decimal::add((string) $already, $amount), (string) $snapshot->tax_amount, 6) === 1) {
                        throw ValidationException::withMessages(['taxes' => ['Return tax exceeds the remaining reversible tax.']]);
                    }
                    $created->push(TransactionTaxSnapshot::create([
                        'transaction_type' => $returnType, 'transaction_id' => $returnId, 'transaction_line_id' => $returnLine->id,
                        'tax_id' => $snapshot->tax_id, 'tax_name' => $snapshot->tax_name, 'tax_code' => $snapshot->tax_code,
                        'calculation_type' => $snapshot->calculation_type, 'rate' => $snapshot->rate, 'behavior' => $snapshot->behavior,
                        'price_type_id' => $snapshot->price_type_id, 'price_type_code' => $snapshot->price_type_code, 'price_type_name' => $snapshot->price_type_name,
                        'quantity' => $returnLine->quantity, 'taxable_base' => Decimal::mul($snapshot->taxable_base, $ratio),
                        'tax_amount' => $amount, 'priority' => $snapshot->priority, 'is_compound' => $snapshot->is_compound,
                        'is_reversal' => true, 'reversal_of_id' => $snapshot->id,
                    ]));
                }
            }
            return $created;
        });
    }

    private function previouslyReturnedQuantity(string $sourceType, int $sourceId, int $currentReturnId, Model $line): string
    {
        $isSale = in_array($sourceType, ['sale_invoice', 'pos'], true);
        $details = $isSale ? 'sale_return_details' : 'purchase_return_details';
        $returns = $isSale ? 'sale_returns' : 'purchase_returns';
        $returnKey = $isSale ? 'sale_return_id' : 'purchase_return_id';
        $sourceKey = $isSale ? 'sale_id' : 'purchase_id';
        $query = DB::table($details.' as d')->join($returns.' as r', 'r.id', '=', 'd.'.$returnKey)
            ->where('r.'.$sourceKey, $sourceId)->where('r.id', '<>', $currentReturnId)
            ->whereNull('r.deleted_at')->where('d.product_id', $line->product_id);
        if ($line->product_variant_id) $query->where('d.product_variant_id', $line->product_variant_id);
        else $query->whereNull('d.product_variant_id');
        return (string) ($query->sum('d.quantity') ?: 0);
    }
}
