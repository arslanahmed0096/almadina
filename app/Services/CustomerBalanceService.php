<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Support\Collection;

class CustomerBalanceService
{
    public function summary(Client|int $client): array
    {
        $clientId = $client instanceof Client ? (int) $client->id : (int) $client;

        return $this->summaries([$clientId])->get($clientId, $this->emptySummary());
    }

    public function summaries(iterable $clientIds): Collection
    {
        $ids = collect($clientIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $openingBalances = Client::query()
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->pluck('opening_balance', 'id');

        $saleDues = Sale::query()
            ->whereNull('deleted_at')
            ->whereIn('client_id', $ids)
            ->whereNotIn('statut', ['cancelled', 'canceled', 'voided'])
            ->selectRaw('client_id, COALESCE(SUM(GrandTotal - paid_amount), 0) AS due')
            ->groupBy('client_id')
            ->pluck('due', 'client_id');

        $returnDues = SaleReturn::query()
            ->whereNull('deleted_at')
            ->whereIn('client_id', $ids)
            ->selectRaw('client_id, COALESCE(SUM(GrandTotal - paid_amount), 0) AS due')
            ->groupBy('client_id')
            ->pluck('due', 'client_id');

        return $ids->mapWithKeys(function (int $clientId) use ($openingBalances, $saleDues, $returnDues) {
            $openingBalance = round(max(0, (float) $openingBalances->get($clientId, 0)), 2);
            $invoiceDue = round(max(0, (float) $saleDues->get($clientId, 0)), 2);
            $returnDue = round(max(0, (float) $returnDues->get($clientId, 0)), 2);

            return [
                $clientId => [
                    'opening_balance' => $openingBalance,
                    'invoice_due' => $invoiceDue,
                    'return_due' => $returnDue,
                    'total_due' => round(max(0, $openingBalance + $invoiceDue - $returnDue), 2),
                ],
            ];
        });
    }

    private function emptySummary(): array
    {
        return [
            'opening_balance' => 0.0,
            'invoice_due' => 0.0,
            'return_due' => 0.0,
            'total_due' => 0.0,
        ];
    }
}
