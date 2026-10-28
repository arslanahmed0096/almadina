<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturnDetails;
use App\Models\ShipmentItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleReturnEligibilityService
{
    public function hasDeliveredItems(Sale $sale): bool
    {
        if ($sale->statut === 'completed') {
            return $sale->relationLoaded('details')
                ? $sale->details->isNotEmpty()
                : $sale->details()->exists();
        }

        if ($sale->relationLoaded('details') && $sale->details->every(fn (SaleDetail $detail) => $detail->relationLoaded('shipmentItem'))) {
            return $sale->details->contains(fn (SaleDetail $detail) => $detail->shipmentItem !== null);
        }

        return ShipmentItem::whereIn('sale_detail_id', $sale->details()->select('id'))->exists();
    }

    /** @return array<int, float> */
    public function returnableQuantities(Sale $sale, ?int $excludingSaleReturnId = null): array
    {
        $details = $sale->relationLoaded('details') ? $sale->details : $sale->details()->get();
        $deliveredQuantities = ShipmentItem::whereIn('sale_detail_id', $details->pluck('id'))
            ->get()->keyBy('sale_detail_id');

        $alreadyReturned = $this->returnedQuantities($details->pluck('id'), $excludingSaleReturnId);

        return $details->mapWithKeys(function (SaleDetail $detail) use ($deliveredQuantities, $alreadyReturned, $sale) {
            $shipmentItem = $deliveredQuantities->get($detail->id);
            $delivered = $shipmentItem
                ? (float) ($shipmentItem->quantity ?? $detail->quantity)
                : ($sale->statut === 'completed' ? (float) $detail->quantity : 0.0);
            $remaining = (float) max(0, $delivered - (float) ($alreadyReturned[$detail->id] ?? 0));

            return [$detail->id => $remaining];
        })->all();
    }

    /**
     * Validate submitted return rows and return them keyed by their original sale detail ID.
     *
     * @return Collection<int, array>
     */
    public function validateDetails(Sale $sale, array $details, ?int $excludingSaleReturnId = null): Collection
    {
        $positive = collect($details)
            ->filter(fn ($detail) => (float) ($detail['quantity'] ?? 0) > 0)
            ->values();

        if ($positive->isEmpty()) {
            throw ValidationException::withMessages([
                'details' => ['Enter a return quantity for at least one delivered item.'],
            ]);
        }

        $available = $this->returnableQuantities($sale, $excludingSaleReturnId);
        $saleDetails = $sale->details->keyBy('id');
        $seen = [];

        foreach ($positive as $index => $detail) {
            $saleDetailId = (int) ($detail['sale_detail_id'] ?? $detail['id'] ?? 0);
            if (! $saleDetailId || ! $saleDetails->has($saleDetailId)) {
                throw ValidationException::withMessages([
                    "details.$index" => ['The selected item does not belong to this sale.'],
                ]);
            }
            $sourceDetail = $saleDetails->get($saleDetailId);
            if (
                (int) ($detail['product_id'] ?? 0) !== (int) $sourceDetail->product_id
                || (int) ($detail['product_variant_id'] ?? 0) !== (int) ($sourceDetail->product_variant_id ?? 0)
                || (int) ($detail['sale_unit_id'] ?? 0) !== (int) ($sourceDetail->sale_unit_id ?? 0)
            ) {
                throw ValidationException::withMessages([
                    "details.$index" => ['The returned product, variant, or unit does not match the delivered sale item.'],
                ]);
            }
            if (isset($seen[$saleDetailId])) {
                throw ValidationException::withMessages([
                    "details.$index" => ['The same sale item cannot be returned twice in one return.'],
                ]);
            }

            $quantity = (float) $detail['quantity'];
            $maximum = (float) ($available[$saleDetailId] ?? 0);
            if ($quantity > $maximum + 0.0001) {
                throw ValidationException::withMessages([
                    "details.$index.quantity" => [
                        $maximum > 0
                            ? "Only {$maximum} delivered unit(s) are available to return for this item."
                            : 'This item has not been delivered and cannot be returned.',
                    ],
                ]);
            }

            $seen[$saleDetailId] = true;
            $price = (float) $sourceDetail->price;
            $discount = (float) $sourceDetail->discount;
            $discountNet = (string) $sourceDetail->discount_method === '2'
                ? $discount
                : $price * $discount / 100;
            $taxRate = (float) $sourceDetail->TaxNet;
            $taxPrice = $taxRate * (($price - $discountNet) / 100);
            $netPrice = (string) $sourceDetail->tax_method === '1'
                ? $price - $discountNet
                : $price - $discountNet - $taxPrice;
            $lineTax = (string) $sourceDetail->tax_method === '1'
                ? $taxPrice
                : $price - $netPrice - $discountNet;

            $positive[$index] = array_merge($detail, [
                'sale_detail_id' => $saleDetailId,
                'product_id' => (int) $sourceDetail->product_id,
                'product_variant_id' => $sourceDetail->product_variant_id,
                'sale_unit_id' => $sourceDetail->sale_unit_id,
                'no_unit' => $sourceDetail->sale_unit_id === null ? 0 : 1,
                'Unit_price' => $price,
                'tax_percent' => $taxRate,
                'tax_method' => $sourceDetail->tax_method,
                'discount' => $discount,
                'discount_Method' => $sourceDetail->discount_method,
                'imei_number' => $sourceDetail->imei_number,
                'subtotal' => round(($netPrice + $lineTax) * $quantity, 2),
            ]);
        }

        return $positive;
    }

    private function returnedQuantities(Collection $saleDetailIds, ?int $excludingSaleReturnId): Collection
    {
        if ($saleDetailIds->isEmpty()) {
            return collect();
        }

        return SaleReturnDetails::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_details.sale_return_id')
            ->whereNull('sale_returns.deleted_at')
            ->whereIn('sale_return_details.sale_detail_id', $saleDetailIds)
            ->when($excludingSaleReturnId, fn ($query) => $query->where('sale_returns.id', '<>', $excludingSaleReturnId))
            ->groupBy('sale_return_details.sale_detail_id')
            ->select('sale_return_details.sale_detail_id', DB::raw('SUM(sale_return_details.quantity) as returned_quantity'))
            ->pluck('returned_quantity', 'sale_return_details.sale_detail_id');
    }
}
