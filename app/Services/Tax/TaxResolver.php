<?php

namespace App\Services\Tax;

use App\Models\Tax;
use App\Models\TaxPriceType;
use App\Models\User;
use App\Models\UserWarehouse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TaxResolver
{
    public function applicable(string $transactionType, int|string $priceType, ?int $warehouseId, ?User $user = null, $date = null): Collection
    {
        if (! in_array($transactionType, Tax::TRANSACTION_TYPES, true)) {
            throw ValidationException::withMessages(['transaction_type' => ['Unsupported tax transaction type.']]);
        }
        $this->assertWarehouseAccess($warehouseId, $user);
        $priceTypeId = is_numeric($priceType) ? (int) $priceType : (int) TaxPriceType::where('code', $priceType)->value('id');

        return Tax::query()->with(['priceTypes', 'transactionTypes', 'warehouses'])
            ->effective($date)->forTransaction($transactionType)->forWarehouse($warehouseId)
            ->whereHas('priceTypes', fn ($q) => $q->where('tax_price_types.id', $priceTypeId))
            ->orderBy('priority')->orderBy('id')->get();
    }

    public function assertSelected(Collection $taxes, string $transactionType, int|string $priceType, ?int $warehouseId, ?User $user = null): void
    {
        $allowed = $this->applicable($transactionType, $priceType, $warehouseId, $user)->pluck('id');
        if ($taxes->pluck('id')->diff($allowed)->isNotEmpty()) {
            throw ValidationException::withMessages(['taxes' => ['An inactive, expired, scoped, or incompatible tax was selected.']]);
        }
    }

    private function assertWarehouseAccess(?int $warehouseId, ?User $user): void
    {
        if (! $warehouseId || ! $user || $user->is_all_warehouses) return;
        if (! UserWarehouse::where('user_id', $user->id)->where('warehouse_id', $warehouseId)->exists()) {
            abort(403, 'Warehouse access denied.');
        }
    }
}
