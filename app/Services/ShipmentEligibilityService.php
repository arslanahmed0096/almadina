<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\SaleReturn;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\Unit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ShipmentEligibilityService
{
    private const MONEY_SCALE = 2;

    /**
     * Build the server-authoritative item eligibility view used by both shipment UIs.
     */
    public function forSale(Sale $sale): array
    {
        $sale->loadMissing([
            'client',
            'details' => fn ($query) => $query->orderBy('id'),
            'details.product:id,name,code,type,unit_sale_id,points,is_batch_tracked,is_active,deleted_at',
            'details.productVariant:id,name,code,deleted_at',
            'shipments.items',
        ]);

        $shippedItems = $sale->shipments
            ->flatMap(fn ($shipment) => $shipment->items)
            ->keyBy(fn ($item) => (int) $item->sale_detail_id);
        $shippedIds = $shippedItems
            ->pluck('sale_detail_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        // Before shipment_items existed, a shipped/delivered header represented every
        // line. The migration backfills these rows, and this fallback protects databases
        // where the legacy data was added during a rolling deployment.
        if ($shippedIds->isEmpty() && $sale->shipments->contains(
            fn ($shipment) => in_array($shipment->status, ['shipped', 'delivered'], true)
        )) {
            $shippedIds = $sale->details->pluck('id')->map(fn ($id) => (int) $id);
        }

        // Money paid against a sale follows the items that were physically shipped
        // first. The remaining paid pool can fund any one unshipped item; it is not
        // permanently tied to the earliest sale-detail row.
        $allocations = $this->allocateSalePayments($sale, $shippedIds->all());
        $availablePaidCents = max(
            0,
            $this->toCents($sale->paid_amount) - $shippedIds->sum(
                fn ($detailId) => $this->toCents($allocations[(int) $detailId]['paid_amount'] ?? 0)
            )
        );

        $credit = $this->calculateCustomerAvailableCredit($sale->client, $sale);
        $overdueInvoices = app(CustomerCreditService::class)->overdueInvoices($sale->client);
        $saleIsShippable = ! $sale->deleted_at
            && ! in_array($sale->statut, ['cancelled', 'canceled'], true)
            && ! SaleReturn::where('sale_id', $sale->id)->whereNull('deleted_at')->exists();
        $items = [];
        foreach ($sale->details as $detail) {
            $allocation = $allocations[(int) $detail->id];
            $isShipped = $shippedIds->contains((int) $detail->id);
            $shippedItem = $shippedItems->get((int) $detail->id);
            if (! $isShipped) {
                // Evaluate every remaining line against the same uncommitted payment
                // pool. Combined selections are checked separately on submit.
                $itemTotalCents = $this->toCents($allocation['item_total']);
                $itemPaidCents = min($itemTotalCents, $availablePaidCents);
                $allocation['paid_amount'] = $this->fromCents($itemPaidCents);
                $allocation['outstanding_amount'] = $this->fromCents($itemTotalCents - $itemPaidCents);
            }
            $eligibility = $this->evaluateItemEligibility(
                $allocation['outstanding_amount'],
                $credit['available_credit'],
                $credit['unlimited']
            );
            if ((float) $allocation['outstanding_amount'] > 0.009 && $overdueInvoices->isNotEmpty()) {
                $overdue = $overdueInvoices->first();
                $eligibility = [
                    'eligible' => false,
                    'eligibility_type' => 'overdue_credit_invoice',
                    'eligibility_message' => 'Cannot be shipped — Customer has overdue invoice '.$overdue['invoice_reference'].'. Outstanding amount: '.$this->money($overdue['outstanding_amount']).'. Due date: '.$overdue['credit_due_date'].'.',
                    'additional_required' => 0.0,
                ];
            }
            if (! $saleIsShippable) {
                $eligibility = [
                    'eligible' => false,
                    'eligibility_type' => 'sale_not_shippable',
                    'eligibility_message' => 'Cannot be shipped — This sale is cancelled, returned, deleted, or otherwise unavailable.',
                    'additional_required' => 0.0,
                ];
            }

            $product = $detail->product;
            $variant = $detail->productVariant;
            if (! $product || $product->deleted_at || ! $product->is_active || ($variant && $variant->deleted_at)) {
                $eligibility = [
                    'eligible' => false,
                    'eligibility_type' => 'product_not_shippable',
                    'eligibility_message' => 'Cannot be shipped — This product is archived, deleted, or inactive.',
                    'additional_required' => 0.0,
                ];
            }
            $items[] = array_merge($allocation, $eligibility, [
                'sale_detail_id' => (int) $detail->id,
                'product_name' => trim(($product->name ?? 'Deleted product').($variant ? ' - '.$variant->name : '')),
                'product_code' => (string) ($variant->code ?? $product->code ?? ''),
                'quantity' => (float) $detail->quantity,
                'is_shipped' => $isShipped,
                'shipped_at' => $this->shippedAt($sale, (int) $detail->id),
                'delivery_method' => $isShipped
                    ? (string) (optional($shippedItem)->delivery_method ?: 'self_delivery')
                    : null,
                'driver_name' => $isShipped
                    ? (string) (optional($shippedItem)->driver_name ?: '')
                    : null,
                'available_credit' => $credit['available_credit'],
            ]);
        }

        $unshipped = array_values(array_filter($items, fn ($item) => ! $item['is_shipped']));

        return [
            'sale_id' => (int) $sale->id,
            'sale_ref' => (string) $sale->Ref,
            'sale_status' => (string) $sale->statut,
            'sale_total' => $this->fromCents(max(0, $this->toCents($sale->GrandTotal))),
            'sale_paid_amount' => $this->fromCents(max(0, $this->toCents($sale->paid_amount))),
            'sale_balance' => $this->fromCents(max(0, $this->toCents($sale->GrandTotal) - $this->toCents($sale->paid_amount))),
            'shipment_status' => (string) ($sale->shipping_status ?: 'ordered'),
            'customer' => [
                'id' => (int) $sale->client_id,
                'name' => (string) ($sale->client->name ?? ''),
            ],
            'credit' => $credit,
            'available_paid_amount' => $this->fromCents($availablePaidCents),
            // Keep every sale line available to edit-shipment screens so previously
            // shipped lines remain visible and can be distinguished from remaining
            // lines. `items` retains the existing unshipped-only API contract.
            'all_items' => $items,
            'items' => $unshipped,
            'shipped_count' => count($items) - count($unshipped),
            'unshipped_count' => count($unshipped),
            'total_count' => count($items),
        ];
    }

    /**
     * Allocate the sale-level paid amount to priority details first, then FIFO by stable
     * sale-detail ID. Shipment callers prioritize already shipped and newly selected
     * lines so payment follows the goods the customer actually receives. Before the
     * allocation, line totals are proportionally reconciled to GrandTotal so existing
     * order discounts, order tax, shipping, and point adjustments are represented once
     * and the effective item totals add up exactly to the sale payable amount.
     */
    public function allocateSalePayments(Sale $sale, array $priorityDetailIds = []): array
    {
        $details = $sale->relationLoaded('details')
            ? $sale->details->sortBy('id')->values()
            : $sale->details()->orderBy('id')->get();

        $rawTotals = $details->map(fn (SaleDetail $detail) => max(0, $this->toCents($detail->total)))->all();
        $effectiveTotals = $this->reconcileItemTotals($rawTotals, max(0, $this->toCents($sale->GrandTotal)));
        $itemTotals = [];
        foreach ($details as $index => $detail) {
            $itemTotals[(int) $detail->id] = $effectiveTotals[$index] ?? 0;
        }

        $detailIds = $details->pluck('id')->map(fn ($id) => (int) $id);
        $priorityIds = collect($priorityDetailIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $detailIds->contains($id))
            ->unique()
            ->values();
        $allocationOrder = $priorityIds
            ->concat($detailIds->reject(fn ($id) => $priorityIds->contains($id)))
            ->values();

        $paidRemaining = min(array_sum($itemTotals), max(0, $this->toCents($sale->paid_amount)));
        $result = [];

        foreach ($allocationOrder as $detailId) {
            $itemTotal = $itemTotals[$detailId] ?? 0;
            $itemPaid = min($itemTotal, $paidRemaining);
            $paidRemaining -= $itemPaid;
            $outstanding = max($itemTotal - $itemPaid, 0);

            $result[$detailId] = [
                'item_total' => $this->fromCents($itemTotal),
                'paid_amount' => $this->fromCents($itemPaid),
                'outstanding_amount' => $this->fromCents($outstanding),
            ];
        }

        return $result;
    }

    /** @return array{eligible: bool, eligibility_type: string, eligibility_message: string, additional_required: float} */
    public function evaluateItemEligibility(float $outstanding, ?float $availableCredit, bool $unlimited = false): array
    {
        $outstandingCents = max(0, $this->toCents($outstanding));
        $availableCents = $unlimited ? PHP_INT_MAX : max(0, $this->toCents($availableCredit ?? 0));

        if ($outstandingCents === 0) {
            return [
                'eligible' => true,
                'eligibility_type' => 'paid',
                'eligibility_message' => 'Eligible for shipment — This item is fully paid.',
                'additional_required' => 0.0,
            ];
        }

        if ($unlimited || $availableCents >= $outstandingCents) {
            return [
                'eligible' => true,
                'eligibility_type' => 'credit',
                'eligibility_message' => 'Eligible for shipment — The outstanding amount is within the customer’s available credit.',
                'additional_required' => 0.0,
            ];
        }

        return [
            'eligible' => false,
            'eligibility_type' => 'insufficient_credit',
            'eligibility_message' => 'Cannot be shipped — The item is unpaid and the customer does not have sufficient available credit.',
            'additional_required' => $this->fromCents($outstandingCents - $availableCents),
        ];
    }

    /**
     * Existing credit usage is the same net-balance definition used by ClientController,
     * plus unpaid portions of lines already shipped from sales that are still Ordered.
     */
    public function calculateCustomerAvailableCredit(?Client $client, ?Sale $currentSale = null): array
    {
        if (! $client) {
            return [
                'credit_limit' => 0.0,
                'current_usage' => 0.0,
                'available_credit' => 0.0,
                'unlimited' => false,
            ];
        }

        return app(CustomerCreditService::class)->availableCredit($client, $currentSale);
    }

    /**
     * Validate and ship selected lines atomically. All eligibility values are recomputed
     * after locking the sale, client, details, shipment rows, and item markers.
     */
    public function shipSelectedItems(Sale $sale, array $saleDetailIds, array $attributes, int $userId): array
    {
        $selectedIds = collect($saleDetailIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($selectedIds->isEmpty()) {
            throw ValidationException::withMessages([
                'sale_detail_ids' => ['Select at least one eligible, unshipped item.'],
            ]);
        }

        $result = DB::transaction(function () use ($sale, $selectedIds, $attributes, $userId) {
            $lockedSale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $client = Client::whereKey($lockedSale->client_id)->lockForUpdate()->firstOrFail();

            if ($lockedSale->deleted_at || in_array($lockedSale->statut, ['cancelled', 'canceled'], true)) {
                throw ValidationException::withMessages(['sale_id' => ['This sale is not eligible for shipment.']]);
            }
            if (SaleReturn::where('sale_id', $lockedSale->id)->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages(['sale_id' => ['A returned sale cannot be shipped.']]);
            }

            $details = SaleDetail::with(['product', 'productVariant'])
                ->where('sale_id', $lockedSale->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedSale->setRelation('details', $details);
            $lockedSale->setRelation('client', $client);

            $selectedDetails = $details->whereIn('id', $selectedIds);
            if ($selectedDetails->count() !== $selectedIds->count()) {
                throw ValidationException::withMessages([
                    'sale_detail_ids' => ['One or more selected items do not belong to this sale.'],
                ]);
            }

            $existingShipmentItems = ShipmentItem::whereIn('sale_detail_id', $details->pluck('id'))
                ->lockForUpdate()
                ->get();
            $alreadyShipped = $existingShipmentItems->whereIn('sale_detail_id', $selectedIds);
            if ($alreadyShipped->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'sale_detail_ids' => ['One or more selected items have already been shipped. Refresh and try again.'],
                ]);
            }

            $allocationPriority = $existingShipmentItems
                ->pluck('sale_detail_id')
                ->map(fn ($id) => (int) $id)
                ->concat($selectedIds)
                ->unique()
                ->values()
                ->all();
            $allocations = $this->allocateSalePayments($lockedSale, $allocationPriority);
            $credit = $this->calculateCustomerAvailableCredit($client, $lockedSale);
            $overdueInvoices = app(CustomerCreditService::class)->overdueInvoices($client);
            $creditRequiredCents = 0;
            $selectedOutstanding = [];
            foreach ($selectedDetails as $detail) {
                if (! $detail->product || $detail->product->deleted_at || ! $detail->product->is_active
                    || ($detail->productVariant && $detail->productVariant->deleted_at)) {
                    throw ValidationException::withMessages([
                        'sale_detail_ids' => ['A selected product is archived, deleted, or inactive and cannot be shipped.'],
                    ]);
                }
                $outstanding = $allocations[(int) $detail->id]['outstanding_amount'];
                $selectedOutstanding[] = $outstanding;
                if ((float) $outstanding > 0.009 && $overdueInvoices->isNotEmpty()) {
                    $overdue = $overdueInvoices->first();
                    throw ValidationException::withMessages([
                        'sale_detail_ids' => ['Shipment blocked because this customer has an overdue credit invoice: '.$overdue['invoice_reference'].'. Outstanding amount: PKR '.$this->money($overdue['outstanding_amount']).'. Due date: '.$overdue['credit_due_date'].'.'],
                    ]);
                }
                $evaluation = $this->evaluateItemEligibility($outstanding, $credit['available_credit'], $credit['unlimited']);
                if (! $evaluation['eligible']) {
                    throw ValidationException::withMessages([
                        'sale_detail_ids' => [$evaluation['eligibility_message']],
                    ]);
                }
                $creditRequiredCents += $this->toCents($outstanding);
            }

            if (! $this->selectionFitsAvailableCredit($selectedOutstanding, $credit['available_credit'], $credit['unlimited'])) {
                $difference = $this->fromCents($creditRequiredCents - $this->toCents($credit['available_credit']));
                throw ValidationException::withMessages([
                    'sale_detail_ids' => ['The selected items exceed the customer’s available credit by '.$this->money($difference).'.'],
                ]);
            }

            $shipment = Shipment::where('sale_id', $lockedSale->id)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if (! $shipment) {
                $shipment = new Shipment;
                $shipment->sale_id = $lockedSale->id;
                $shipment->user_id = $userId;
                $shipment->Ref = $attributes['Ref'] ?? $this->nextShipmentReference();
            }
            $shipment->delivered_to = $attributes['delivered_to'] ?? $shipment->delivered_to;
            $shipment->shipping_address = $attributes['shipping_address'] ?? $shipment->shipping_address;
            $shipment->shipping_details = $attributes['shipping_details'] ?? $shipment->shipping_details;
            $shipment->delivery_method = $attributes['delivery_method'] ?? ($shipment->delivery_method ?: 'self_delivery');
            $shipment->driver_name = $shipment->delivery_method === 'almadina_driver'
                ? ($attributes['driver_name'] ?? $shipment->driver_name)
                : null;
            $shipment->status = 'ordered';
            $shipment->save();

            $inventoryWasAlreadyConsumed = $lockedSale->statut === 'completed';
            foreach ($selectedDetails as $detail) {
                $allocation = $allocations[(int) $detail->id];
                ShipmentItem::create([
                    'shipment_id' => $shipment->id,
                    'sale_detail_id' => $detail->id,
                    'shipped_by' => $userId,
                    'delivery_method' => $attributes['delivery_method'] ?? 'self_delivery',
                    'driver_name' => ($attributes['delivery_method'] ?? 'self_delivery') === 'almadina_driver'
                        ? ($attributes['driver_name'] ?? null)
                        : null,
                    'item_total' => $allocation['item_total'],
                    'paid_amount' => $allocation['paid_amount'],
                    'outstanding_amount' => $allocation['outstanding_amount'],
                    'credit_amount' => $allocation['outstanding_amount'],
                    'shipped_at' => now(),
                ]);

                if (! $inventoryWasAlreadyConsumed) {
                    $this->consumeInventory($lockedSale, $detail);
                }
            }

            $shippedCount = ShipmentItem::whereIn('sale_detail_id', $details->pluck('id'))->count();
            $allShipped = $details->isNotEmpty() && $shippedCount === $details->count();
            $shipment->status = $allShipped ? 'shipped' : 'ordered';
            $shipment->save();

            if ($allShipped) {
                $lockedSale->statut = 'completed';
                $lockedSale->shipping_status = 'shipped';
                $this->awardPendingLoyaltyPoints($lockedSale, $client, $details);
            } elseif (! $inventoryWasAlreadyConsumed) {
                $lockedSale->statut = 'ordered';
                $lockedSale->shipping_status = 'ordered';
            }
            if ($creditRequiredCents > 0 && ! $lockedSale->credit_due_date) {
                app(CustomerCreditService::class)->applySnapshot($lockedSale);
            }
            $lockedSale->save();

            return [
                'sale' => $lockedSale->fresh(),
                'shipment' => $shipment->fresh(),
                'all_shipped' => $allShipped,
                'shipped_count' => $shippedCount,
                'total_count' => $details->count(),
            ];
        }, 10);

        if ($result['all_shipped']) {
            try {
                app(CommissionService::class)->calculateForSale($result['sale']);
            } catch (\Throwable $e) {
                report($e);
            }

            if (class_exists(\App\Jobs\SyncSaleToQuickBooks::class)) {
                $realm = $result['sale']->quickbooks_realm_id ?: env('QUICKBOOKS_REALM_ID');
                \App\Jobs\SyncSaleToQuickBooks::dispatch($result['sale']->id, $realm)->afterCommit();
            }
        }

        return $result;
    }

    public function determineSaleShipmentStatus(int $shippedCount, int $totalCount): string
    {
        return $totalCount > 0 && $shippedCount >= $totalCount ? 'completed' : 'ordered';
    }

    public function selectionFitsAvailableCredit(array $outstandingAmounts, ?float $availableCredit, bool $unlimited = false): bool
    {
        if ($unlimited) {
            return true;
        }

        $required = array_sum(array_map(fn ($amount) => max(0, $this->toCents($amount)), $outstandingAmounts));

        return $required <= max(0, $this->toCents($availableCredit ?? 0));
    }

    private function calculateOrderedShipmentCreditUsage(int $clientId): float
    {
        if (! Schema::hasTable('shipment_items')) {
            return 0.0;
        }

        $saleIds = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->join('sales', 'sales.id', '=', 'shipments.sale_id')
            ->whereNull('shipments.deleted_at')
            ->whereNull('sales.deleted_at')
            ->where('sales.client_id', $clientId)
            ->whereNotIn('sales.statut', ['completed', 'cancelled', 'canceled'])
            ->distinct()
            ->pluck('sales.id');

        $reservedCents = 0;
        foreach ($saleIds as $saleId) {
            $orderedSale = Sale::with(['details' => fn ($query) => $query->orderBy('id')])->find($saleId);
            if (! $orderedSale) {
                continue;
            }
            $shippedIds = DB::table('shipment_items')
                ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
                ->where('shipments.sale_id', $saleId)
                ->whereNull('shipments.deleted_at')
                ->pluck('shipment_items.sale_detail_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $allocations = $this->allocateSalePayments($orderedSale, $shippedIds);
            foreach ($shippedIds as $detailId) {
                if (isset($allocations[$detailId])) {
                    $reservedCents += $this->toCents($allocations[$detailId]['outstanding_amount']);
                }
            }
        }

        return $this->fromCents($reservedCents);
    }

    private function reconcileItemTotals(array $rawTotals, int $saleTotal): array
    {
        if (! $rawTotals) {
            return [];
        }
        $rawSum = array_sum($rawTotals);
        if ($rawSum <= 0) {
            return array_fill(0, count($rawTotals), 0);
        }

        $result = [];
        $allocated = 0;
        $last = count($rawTotals) - 1;
        foreach ($rawTotals as $index => $rawTotal) {
            $amount = $index === $last
                ? max($saleTotal - $allocated, 0)
                : (int) round($saleTotal * ($rawTotal / $rawSum), 0, PHP_ROUND_HALF_UP);
            $amount = min($amount, max($saleTotal - $allocated, 0));
            $result[] = $amount;
            $allocated += $amount;
        }

        return $result;
    }

    private function consumeInventory(Sale $sale, SaleDetail $detail): void
    {
        $product = $detail->product ?: Product::find($detail->product_id);
        if (! $product || $product->type === 'is_service') {
            return;
        }

        $unitId = $detail->sale_unit_id ?: $product->unit_sale_id;
        $unit = $unitId ? Unit::find($unitId) : null;
        if (! $unit) {
            return;
        }

        $warehouseStock = product_warehouse::whereNull('deleted_at')
            ->where('warehouse_id', $sale->warehouse_id)
            ->where('product_id', $detail->product_id)
            ->when($detail->product_variant_id, fn ($query) => $query->where('product_variant_id', $detail->product_variant_id))
            ->when(! $detail->product_variant_id, fn ($query) => $query->whereNull('product_variant_id'))
            ->lockForUpdate()
            ->first();

        if ($warehouseStock) {
            $baseQuantity = $unit->operator === '/'
                ? (float) $detail->quantity / (float) $unit->operator_value
                : (float) $detail->quantity * (float) $unit->operator_value;
            $warehouseStock->qte = (float) $warehouseStock->qte - $baseQuantity;
            $warehouseStock->save();
        }

        app(BatchService::class)->applyForShippedSaleDetail($sale, $detail);
    }

    private function awardPendingLoyaltyPoints(Sale $sale, Client $client, Collection $details): void
    {
        if ((float) $sale->earned_points > 0 || ! $client->is_royalty_eligible) {
            return;
        }

        $earned = $details->sum(function (SaleDetail $detail) {
            return (float) $detail->quantity * (float) ($detail->product->points ?? 0);
        });
        if ($earned <= 0) {
            return;
        }

        $client->increment('points', $earned);
        $sale->earned_points = $earned;
    }

    private function nextShipmentReference(): string
    {
        $last = Shipment::lockForUpdate()->orderByDesc('id')->first();
        if (! $last || ! preg_match('/^(.*_)(\d+)$/', (string) $last->Ref, $matches)) {
            return 'SM_1111';
        }

        return $matches[1].((int) $matches[2] + 1);
    }

    private function shippedAt(Sale $sale, int $detailId): ?string
    {
        foreach ($sale->shipments as $shipment) {
            $item = $shipment->items->firstWhere('sale_detail_id', $detailId);
            if ($item) {
                return optional($item->shipped_at)->toIso8601String();
            }
        }

        return null;
    }

    private function toCents($amount): int
    {
        return (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function fromCents(int $amount): float
    {
        return round($amount / 100, self::MONEY_SCALE);
    }

    private function money(float $amount): string
    {
        return number_format($amount, self::MONEY_SCALE, '.', '');
    }
}
