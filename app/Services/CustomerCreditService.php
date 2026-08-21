<?php

namespace App\Services;

use App\Models\BusinessPolicy;
use App\Models\Client;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CustomerCreditService
{
    public const POLICY_KEY = 'credit_limit';
    public const DEFAULT_DAYS = 30;
    public const ALLOWED_DAYS = [5, 10, 15, 20, 25, 30];

    public function policy(): array
    {
        if (! Schema::hasTable('policies')) {
            return $this->defaultPolicy();
        }

        $policy = BusinessPolicy::where('policy_key', self::POLICY_KEY)->first();
        if (! $policy) {
            Log::warning('Credit Limit Policy is missing; the safe 30-day default is in use.');
            return $this->defaultPolicy();
        }

        $days = (int) $policy->policy_value;
        if (! in_array($days, self::ALLOWED_DAYS, true)) {
            Log::warning('Credit Limit Policy has an invalid value; the safe 30-day default is in use.', ['value' => $policy->policy_value]);
            $days = self::DEFAULT_DAYS;
        }

        return [
            'id' => $policy->id,
            'policy_key' => $policy->policy_key,
            'policy_name' => $policy->policy_name,
            'allowed_credit_days' => $days,
            'is_active' => (bool) $policy->is_active,
            'allowed_values' => self::ALLOWED_DAYS,
        ];
    }

    public function applySnapshot(Sale $sale, ?int $days = null): Sale
    {
        if (! Schema::hasColumn('sales', 'credit_days') || $this->outstandingForSale($sale) <= 0) {
            return $sale;
        }

        $policy = $this->policy();
        $days = $days ?? $policy['allowed_credit_days'];
        if (! in_array((int) $days, self::ALLOWED_DAYS, true)) {
            throw ValidationException::withMessages(['credit_days' => ['Allowed Credit Days must be one of 5, 10, 15, 20, 25, or 30.']]);
        }

        $invoiceDate = Carbon::parse($sale->date ?: now(), config('app.timezone'));
        $sale->credit_days = (int) $days;
        $sale->credit_due_date = $invoiceDate->copy()->addDays((int) $days)->toDateString();
        return $sale;
    }

    public function outstandingForSale(Sale $sale): float
    {
        $base = max(0, round((float) $sale->GrandTotal - (float) $sale->paid_amount, 2));
        if ($base <= 0 || ! Schema::hasTable('sale_returns')) {
            return $base;
        }

        $returns = DB::table('sale_returns')
            ->whereNull('deleted_at')
            ->where('sale_id', $sale->id)
            ->when(Schema::hasColumn('sale_returns', 'statut'), fn ($query) => $query->whereNotIn('statut', ['cancelled', 'canceled', 'voided']))
            ->get(['GrandTotal', 'paid_amount']);
        $returnDue = (float) $returns->sum(fn ($return) => max(0, (float) $return->GrandTotal - (float) $return->paid_amount));

        return max(0, round($base - $returnDue, 2));
    }

    public function creditUsage(Client $client, ?Sale $excludeSale = null): float
    {
        $query = Sale::query()
            ->whereNull('deleted_at')
            ->where('client_id', $client->id)
            ->where('statut', 'completed')
            ->whereNotIn('statut', ['cancelled', 'canceled', 'voided']);

        if ($excludeSale) {
            $query->whereKeyNot($excludeSale->id);
        }

        $sales = $query->get(['id', 'GrandTotal', 'paid_amount', 'statut']);
        $invoiceDue = $sales->sum(fn (Sale $sale) => $this->outstandingForSale($sale));
        $reserved = $this->orderedShipmentCreditUsage(
            (int) $client->id,
            $excludeSale && $excludeSale->statut === 'completed' ? $excludeSale : null
        );

        return max(0, round((float) ($client->opening_balance ?? 0) + $invoiceDue + $reserved, 2));
    }

    public function availableCredit(Client $client, ?Sale $excludeSale = null): array
    {
        $limit = max(0, round((float) ($client->credit_limit ?? 0), 2));
        $used = $this->creditUsage($client, $excludeSale);

        return [
            'credit_limit' => $limit,
            'current_usage' => $used,
            'total_outstanding_credit' => $used,
            'available_credit' => (float) max(0, round($limit - $used, 2)),
            'unlimited' => false,
        ];
    }

    public function overdueInvoices(Client|int $client, ?Sale $excludeSale = null): Collection
    {
        if (! Schema::hasColumn('sales', 'credit_due_date')) {
            return collect();
        }

        $clientId = $client instanceof Client ? $client->id : $client;
        $query = $this->overdueQuery()->where('client_id', $clientId);
        if ($excludeSale) {
            $query->whereKeyNot($excludeSale->id);
        }

        return $query->get()->map(function (Sale $sale) {
            $outstanding = $this->outstandingForSale($sale);
            return [
                'id' => (int) $sale->id,
                'invoice_reference' => (string) $sale->Ref,
                'invoice_date' => Carbon::parse($sale->date)->toDateString(),
                'credit_due_date' => Carbon::parse($sale->credit_due_date)->toDateString(),
                'original_amount' => round((float) $sale->GrandTotal, 2),
                'paid_amount' => round((float) $sale->paid_amount, 2),
                'outstanding_amount' => $outstanding,
                'days_overdue' => Carbon::parse($sale->credit_due_date)->startOfDay()->diffInDays(Carbon::today(config('app.timezone'))),
                'warehouse_id' => (int) $sale->warehouse_id,
            ];
        })->filter(fn (array $invoice) => $invoice['outstanding_amount'] > 0.009)->values();
    }

    public function overdueQuery(): Builder
    {
        return Sale::query()
            ->whereNull('deleted_at')
            ->whereNotNull('credit_due_date')
            ->whereDate('credit_due_date', '<', Carbon::today(config('app.timezone'))->toDateString())
            ->whereNotIn('statut', ['cancelled', 'canceled', 'voided'])
            ->whereRaw('(GrandTotal - paid_amount) > 0.009');
    }

    public function eligibility(Client $client, float $requestedAmount, ?Sale $excludeSale = null): array
    {
        $requestedAmount = max(0, round($requestedAmount, 2));
        $credit = $this->availableCredit($client, $excludeSale);
        $policy = $this->policy();
        $overdue = $this->overdueInvoices($client, $excludeSale);
        $allowed = true;
        $code = null;
        $message = null;

        if ($requestedAmount > 0 && ! $policy['is_active']) {
            $allowed = false;
            $code = 'CREDIT_NOT_ALLOWED';
            $message = 'Credit transactions are currently disabled by the Credit Limit Policy.';
        } elseif ($requestedAmount > 0 && $overdue->isNotEmpty()) {
            $allowed = false;
            $code = 'OVERDUE_CREDIT_INVOICE';
            $message = 'Credit transaction blocked. This customer has an overdue invoice. Please clear the outstanding balance before creating another credit invoice.';
        } elseif ($requestedAmount > $credit['available_credit'] + 0.001) {
            $allowed = false;
            $code = 'CREDIT_LIMIT_EXCEEDED';
            $message = 'Credit transaction blocked because the requested amount exceeds the customer\'s available credit.';
        }

        return array_merge($credit, [
            'allowed' => $allowed,
            'requested_credit_amount' => $requestedAmount,
            'has_overdue_invoices' => $overdue->isNotEmpty(),
            'overdue_invoices' => $overdue->all(),
            'rejection_code' => $code,
            'rejection_message' => $message,
            'allowed_credit_days' => $policy['allowed_credit_days'],
            'policy_active' => $policy['is_active'],
        ]);
    }

    public function assertEligible(Client $client, float $requestedAmount, ?Sale $excludeSale = null): array
    {
        $result = $this->eligibility($client, $requestedAmount, $excludeSale);
        if (! $result['allowed']) {
            throw ValidationException::withMessages([
                'credit' => [$result['rejection_message']],
                'rejection_code' => [$result['rejection_code']],
                'customer_credit_limit' => [(string) $result['credit_limit']],
                'total_outstanding_credit' => [(string) $result['total_outstanding_credit']],
                'available_credit' => [(string) $result['available_credit']],
                'requested_credit_amount' => [(string) $result['requested_credit_amount']],
                'overdue_invoices' => [json_encode($result['overdue_invoices'])],
            ]);
        }
        return $result;
    }

    public function summary(Client $client): array
    {
        $credit = $this->availableCredit($client);
        $unpaid = Sale::query()
            ->whereNull('deleted_at')->where('client_id', $client->id)
            ->whereNotIn('statut', ['cancelled', 'canceled', 'voided'])
            ->whereRaw('(GrandTotal - paid_amount) > 0.009')->get();
        $overdue = $this->overdueInvoices($client);

        return array_merge($credit, [
            'total_overdue_amount' => round($overdue->sum('outstanding_amount'), 2),
            'unpaid_invoice_count' => $unpaid->filter(fn (Sale $sale) => $this->outstandingForSale($sale) > 0.009)->count(),
            'overdue_invoice_count' => $overdue->count(),
            'oldest_overdue_date' => $overdue->min('credit_due_date'),
        ]);
    }

    public function creditStatus(Sale $sale): string
    {
        if ($this->outstandingForSale($sale) <= 0.009) return 'Paid';
        if (! $sale->credit_due_date) return 'Not Applicable';
        $due = Carbon::parse($sale->credit_due_date)->startOfDay();
        $today = Carbon::today(config('app.timezone'));
        if ($due->lt($today)) return 'Overdue';
        if ($due->equalTo($today)) return 'Due Today';
        return 'Within Due Date';
    }

    private function orderedShipmentCreditUsage(int $clientId, ?Sale $excludeSale): float
    {
        if (! Schema::hasTable('shipment_items')) return 0.0;
        $query = DB::table('shipment_items')
            ->join('shipments', 'shipment_items.shipment_id', '=', 'shipments.id')
            ->join('sales', 'shipments.sale_id', '=', 'sales.id')
            ->whereNull('shipments.deleted_at')->whereNull('sales.deleted_at')
            ->where('sales.client_id', $clientId)->where('sales.statut', 'ordered')
            ->groupBy('sales.id', 'sales.paid_amount')
            ->selectRaw('sales.id, sales.paid_amount, SUM(shipment_items.item_total) AS shipped_total');
        if ($excludeSale) $query->where('sales.id', '<>', $excludeSale->id);
        return round((float) $query->get()->sum(
            fn ($row) => max(0, (float) $row->shipped_total - (float) $row->paid_amount)
        ), 2);
    }

    private function defaultPolicy(): array
    {
        return [
            'id' => null, 'policy_key' => self::POLICY_KEY, 'policy_name' => 'Credit Limit Policy',
            'allowed_credit_days' => self::DEFAULT_DAYS, 'is_active' => true,
            'allowed_values' => self::ALLOWED_DAYS,
        ];
    }
}
