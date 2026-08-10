<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductStockOverviewService
{
    public function build(Product $product, array $warehouseIds, ?int $visibleUserId = null): array
    {
        $warehouseIds = collect($warehouseIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $warehouseStock = $this->warehouseStock((int) $product->id, $warehouseIds);
        $purchased = $this->transactionQuantity(
            'purchase_details',
            'purchases',
            'purchase_id',
            'purchase_unit_id',
            'received',
            (int) $product->id,
            $warehouseIds,
            $visibleUserId
        );
        $purchaseReturned = $this->transactionQuantity(
            'purchase_return_details',
            'purchase_returns',
            'purchase_return_id',
            'purchase_unit_id',
            'completed',
            (int) $product->id,
            $warehouseIds,
            $visibleUserId
        );
        $sold = $this->transactionQuantity(
            'sale_details',
            'sales',
            'sale_id',
            'sale_unit_id',
            'completed',
            (int) $product->id,
            $warehouseIds,
            $visibleUserId
        );
        $saleReturned = $this->transactionQuantity(
            'sale_return_details',
            'sale_returns',
            'sale_return_id',
            'sale_unit_id',
            'received',
            (int) $product->id,
            $warehouseIds,
            $visibleUserId
        );

        $customers = $this->customerBreakdown(
            (int) $product->id,
            $warehouseIds,
            $visibleUserId
        );

        return [
            'product' => [
                'id' => (int) $product->id,
                'code' => (string) ($product->code ?? ''),
                'name' => (string) ($product->name ?? ''),
                'unit' => (string) (optional($product->unit)->ShortName ?? ''),
            ],
            'totals' => [
                'purchased' => $this->quantity($purchased),
                'purchase_returned' => $this->quantity($purchaseReturned),
                'net_purchased' => $this->quantity($purchased - $purchaseReturned),
                'in_stock' => $this->quantity($warehouseStock->sum('quantity')),
                'sold' => $this->quantity($sold),
                'sale_returned' => $this->quantity($saleReturned),
                'net_sold' => $this->quantity($sold - $saleReturned),
                'customers' => $customers->count(),
            ],
            'warehouses' => $warehouseStock->values()->all(),
            'customers' => $customers->values()->all(),
        ];
    }

    private function warehouseStock(int $productId, array $warehouseIds): Collection
    {
        if (empty($warehouseIds)) {
            return collect();
        }

        $quantities = DB::table('product_warehouse')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->whereIn('warehouse_id', $warehouseIds)
            ->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, COALESCE(SUM(qte), 0) as quantity')
            ->pluck('quantity', 'warehouse_id');

        return DB::table('warehouses')
            ->whereNull('deleted_at')
            ->whereIn('id', $warehouseIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($warehouse) use ($quantities) {
                return [
                    'warehouse_id' => (int) $warehouse->id,
                    'warehouse_name' => (string) $warehouse->name,
                    'quantity' => $this->quantity($quantities->get($warehouse->id, 0)),
                ];
            });
    }

    private function transactionQuantity(
        string $detailTable,
        string $headerTable,
        string $headerForeignKey,
        string $unitForeignKey,
        string $completedStatus,
        int $productId,
        array $warehouseIds,
        ?int $visibleUserId
    ): float {
        if (empty($warehouseIds)) {
            return 0.0;
        }

        $query = DB::table($detailTable.' as detail')
            ->join($headerTable.' as header', 'header.id', '=', 'detail.'.$headerForeignKey)
            ->leftJoin('units as movement_unit', 'movement_unit.id', '=', 'detail.'.$unitForeignKey)
            ->where('detail.product_id', $productId)
            ->whereNull('header.deleted_at')
            ->where('header.statut', $completedStatus)
            ->whereIn('header.warehouse_id', $warehouseIds)
            ->when($visibleUserId !== null, function ($query) use ($visibleUserId) {
                $query->where('header.user_id', $visibleUserId);
            });

        return (float) ($query->selectRaw(
            'COALESCE(SUM('.$this->baseQuantityExpression('detail', 'movement_unit').'), 0) as aggregate_quantity'
        )->value('aggregate_quantity') ?? 0);
    }

    private function customerBreakdown(
        int $productId,
        array $warehouseIds,
        ?int $visibleUserId
    ): Collection {
        if (empty($warehouseIds)) {
            return collect();
        }

        $sales = DB::table('sale_details as detail')
            ->join('sales as header', 'header.id', '=', 'detail.sale_id')
            ->leftJoin('clients as client', 'client.id', '=', 'header.client_id')
            ->leftJoin('units as movement_unit', 'movement_unit.id', '=', 'detail.sale_unit_id')
            ->where('detail.product_id', $productId)
            ->whereNull('header.deleted_at')
            ->where('header.statut', 'completed')
            ->whereIn('header.warehouse_id', $warehouseIds)
            ->when($visibleUserId !== null, function ($query) use ($visibleUserId) {
                $query->where('header.user_id', $visibleUserId);
            })
            ->groupBy('header.client_id', 'client.name', 'client.phone')
            ->selectRaw(
                "header.client_id, COALESCE(client.name, 'Deleted customer') as customer_name, client.phone, "
                .'COALESCE(SUM('.$this->baseQuantityExpression('detail', 'movement_unit').'), 0) as sold_quantity, '
                .'COUNT(DISTINCT header.id) as sale_count, MAX(header.date) as last_sale_date'
            )
            ->get()
            ->keyBy(fn ($row) => (string) ($row->client_id ?? 'deleted'));

        $returns = DB::table('sale_return_details as detail')
            ->join('sale_returns as header', 'header.id', '=', 'detail.sale_return_id')
            ->leftJoin('units as movement_unit', 'movement_unit.id', '=', 'detail.sale_unit_id')
            ->where('detail.product_id', $productId)
            ->whereNull('header.deleted_at')
            ->where('header.statut', 'received')
            ->whereIn('header.warehouse_id', $warehouseIds)
            ->when($visibleUserId !== null, function ($query) use ($visibleUserId) {
                $query->where('header.user_id', $visibleUserId);
            })
            ->groupBy('header.client_id')
            ->selectRaw(
                'header.client_id, COALESCE(SUM('.$this->baseQuantityExpression('detail', 'movement_unit').'), 0) as returned_quantity'
            )
            ->pluck('returned_quantity', 'client_id');

        return $sales
            ->map(function ($row) use ($returns) {
                $returned = (float) $returns->get($row->client_id, 0);
                $sold = (float) $row->sold_quantity;

                return [
                    'customer_id' => $row->client_id ? (int) $row->client_id : null,
                    'customer_name' => (string) $row->customer_name,
                    'phone' => (string) ($row->phone ?? ''),
                    'sold_quantity' => $this->quantity($sold),
                    'returned_quantity' => $this->quantity($returned),
                    'net_quantity' => $this->quantity($sold - $returned),
                    'sale_count' => (int) $row->sale_count,
                    'last_sale_date' => $row->last_sale_date,
                ];
            })
            ->sortByDesc('net_quantity');
    }

    private function baseQuantityExpression(string $detailAlias, string $unitAlias): string
    {
        return "CASE
            WHEN {$unitAlias}.operator = '/' AND COALESCE({$unitAlias}.operator_value, 0) <> 0
                THEN {$detailAlias}.quantity / {$unitAlias}.operator_value
            WHEN {$unitAlias}.operator = '*'
                THEN {$detailAlias}.quantity * COALESCE({$unitAlias}.operator_value, 1)
            ELSE {$detailAlias}.quantity
        END";
    }

    private function quantity($value): float
    {
        return round((float) $value, 4);
    }
}
