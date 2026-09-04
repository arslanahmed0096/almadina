<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyReportService
{
    public function build(Carbon $date, Collection $warehouses, bool $includeGlobalBalances, ?int $providerId = null): array
    {
        $day = $date->toDateString();
        $warehouseIds = $warehouses->pluck('id')->map(fn ($id) => (int) $id)->values();

        $sales = DB::table('sales')
            ->whereNull('deleted_at')
            ->where('date', $day)
            ->where('statut', 'completed')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('warehouse_id, SUM(GrandTotal) AS amount')
            ->groupBy('warehouse_id')
            ->pluck('amount', 'warehouse_id');
        $returns = DB::table('sale_returns')
            ->whereNull('deleted_at')
            ->where('date', $day)
            ->where('statut', 'completed')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('warehouse_id, SUM(GrandTotal) AS amount')
            ->groupBy('warehouse_id')
            ->pluck('amount', 'warehouse_id');

        $salesByBranch = $warehouses->map(function ($warehouse) use ($sales, $returns) {
            $gross = (float) ($sales[$warehouse->id] ?? 0);
            $returned = (float) ($returns[$warehouse->id] ?? 0);

            return [
                'warehouse_id' => (int) $warehouse->id,
                'warehouse' => $warehouse->name,
                'gross_sales' => $this->money($gross),
                'sale_returns' => $this->money($returned),
                'net_sales' => $this->money($gross - $returned),
            ];
        })->values();

        $registers = DB::table('cash_registers')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereDate('opened_at', $day)
            ->selectRaw('COALESCE(SUM(opening_balance), 0) AS opening_balance')
            ->selectRaw('COALESCE(SUM(cash_in), 0) AS cash_in')
            ->selectRaw('COALESCE(SUM(cash_out), 0) AS cash_out')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN closing_balance ELSE 0 END), 0) AS closing_balance', ['closed'])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN difference ELSE 0 END), 0) AS difference', ['closed'])
            ->first();

        $customerReceipts = $this->paymentTotals(
            DB::table('payment_sales')
                ->join('sales', 'sales.id', '=', 'payment_sales.sale_id')
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payment_sales.payment_method_id')
                ->whereNull('payment_sales.deleted_at')
                ->whereNull('sales.deleted_at')
                ->where('payment_sales.date', $day)
                ->whereIn('sales.warehouse_id', $warehouseIds),
            'payment_sales'
        );
        $customerOpeningReceipts = $includeGlobalBalances
            ? $this->paymentTotals(
                DB::table('client_opening_balance_payments')
                    ->leftJoin('payment_methods', 'payment_methods.id', '=', 'client_opening_balance_payments.payment_method_id')
                    ->whereNull('client_opening_balance_payments.deleted_at')
                    ->where('client_opening_balance_payments.date', $day),
                'client_opening_balance_payments',
                'montant'
            )
            : collect();
        $supplierReturnReceipts = $this->paymentTotals(
            DB::table('payment_purchase_returns')
                ->join('purchase_returns', 'purchase_returns.id', '=', 'payment_purchase_returns.purchase_return_id')
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payment_purchase_returns.payment_method_id')
                ->whereNull('payment_purchase_returns.deleted_at')
                ->whereNull('purchase_returns.deleted_at')
                ->where('payment_purchase_returns.date', $day)
                ->when($providerId, fn ($query) => $query->where('purchase_returns.provider_id', $providerId))
                ->whereIn('purchase_returns.warehouse_id', $warehouseIds),
            'payment_purchase_returns'
        );
        $operatingExpenseMethods = $this->paymentTotals(
            DB::table('expenses')
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'expenses.payment_method_id')
                ->whereNull('expenses.deleted_at')
                ->where('expenses.date', $day)
                ->whereIn('expenses.warehouse_id', $warehouseIds),
            'expenses',
            'amount'
        );
        $supplierPaymentMethods = $this->paymentTotals(
            DB::table('payment_purchases')
                ->join('purchases', 'purchases.id', '=', 'payment_purchases.purchase_id')
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payment_purchases.payment_method_id')
                ->whereNull('payment_purchases.deleted_at')
                ->whereNull('purchases.deleted_at')
                ->where('payment_purchases.date', $day)
                ->when($providerId, fn ($query) => $query->where('purchases.provider_id', $providerId))
                ->whereIn('purchases.warehouse_id', $warehouseIds),
            'payment_purchases'
        );
        $providerOpeningPaymentMethods = $includeGlobalBalances
            ? $this->paymentTotals(
                DB::table('provider_opening_balance_payments')
                    ->leftJoin('payment_methods', 'payment_methods.id', '=', 'provider_opening_balance_payments.payment_method_id')
                    ->whereNull('provider_opening_balance_payments.deleted_at')
                    ->where('provider_opening_balance_payments.date', $day)
                    ->when($providerId, fn ($query) => $query->where('provider_opening_balance_payments.provider_id', $providerId)),
                'provider_opening_balance_payments',
                'montant'
            )
            : collect();
        $customerRefundMethods = $this->paymentTotals(
            DB::table('payment_sale_returns')
                ->join('sale_returns', 'sale_returns.id', '=', 'payment_sale_returns.sale_return_id')
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payment_sale_returns.payment_method_id')
                ->whereNull('payment_sale_returns.deleted_at')
                ->whereNull('sale_returns.deleted_at')
                ->where('payment_sale_returns.date', $day)
                ->whereIn('sale_returns.warehouse_id', $warehouseIds),
            'payment_sale_returns'
        );

        $outflows = collect();
        $expenses = DB::table('expenses')
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'expenses.warehouse_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'expenses.payment_method_id')
            ->whereNull('expenses.deleted_at')
            ->where('expenses.date', $day)
            ->whereIn('expenses.warehouse_id', $warehouseIds)
            ->orderBy('expenses.id')
            ->get([
                'expenses.id', 'expenses.Ref as reference', 'expenses.details as details', 'expenses.amount',
                'expenses.created_at', 'expense_categories.name as category', 'warehouses.name as warehouse',
                'payment_methods.name as payment_method',
            ])
            ->map(fn ($row) => [
                'key' => 'expense-'.$row->id,
                'type' => 'Operating expense',
                'reference' => $row->reference,
                'description' => filled($row->details) ? $row->details : ($row->category ?: 'Expense'),
                'warehouse' => $row->warehouse,
                'payment_method' => $row->payment_method ?: 'Unspecified',
                'amount' => $this->money($row->amount),
                'created_at' => $row->created_at,
            ]);
        $outflows = $outflows->merge($expenses);

        $supplierPayments = DB::table('payment_purchases')
            ->join('purchases', 'purchases.id', '=', 'payment_purchases.purchase_id')
            ->leftJoin('providers', 'providers.id', '=', 'purchases.provider_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'purchases.warehouse_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payment_purchases.payment_method_id')
            ->whereNull('payment_purchases.deleted_at')
            ->whereNull('purchases.deleted_at')
            ->where('payment_purchases.date', $day)
            ->when($providerId, fn ($query) => $query->where('purchases.provider_id', $providerId))
            ->whereIn('purchases.warehouse_id', $warehouseIds)
            ->orderBy('payment_purchases.id')
            ->get([
                'payment_purchases.id', 'payment_purchases.Ref as reference', 'payment_purchases.notes',
                'payment_purchases.montant as amount', 'payment_purchases.created_at',
                'providers.name as provider', 'warehouses.name as warehouse', 'payment_methods.name as payment_method',
            ])
            ->map(fn ($row) => [
                'key' => 'supplier-payment-'.$row->id,
                'type' => 'Supplier payment',
                'reference' => $row->reference,
                'description' => 'Payment to '.($row->provider ?: 'supplier').(filled($row->notes) ? ' — '.$row->notes : ''),
                'warehouse' => $row->warehouse,
                'payment_method' => $row->payment_method ?: 'Unspecified',
                'amount' => $this->money($row->amount),
                'created_at' => $row->created_at,
            ]);
        $outflows = $outflows->merge($supplierPayments);

        if ($includeGlobalBalances) {
            $providerOpeningPayments = DB::table('provider_opening_balance_payments')
                ->leftJoin('providers', 'providers.id', '=', 'provider_opening_balance_payments.provider_id')
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'provider_opening_balance_payments.payment_method_id')
                ->whereNull('provider_opening_balance_payments.deleted_at')
                ->where('provider_opening_balance_payments.date', $day)
                ->when($providerId, fn ($query) => $query->where('provider_opening_balance_payments.provider_id', $providerId))
                ->orderBy('provider_opening_balance_payments.id')
                ->get([
                    'provider_opening_balance_payments.id', 'provider_opening_balance_payments.Ref as reference',
                    'provider_opening_balance_payments.notes', 'provider_opening_balance_payments.montant as amount',
                    'provider_opening_balance_payments.created_at', 'providers.name as provider',
                    'payment_methods.name as payment_method',
                ])
                ->map(fn ($row) => [
                    'key' => 'provider-opening-payment-'.$row->id,
                    'type' => 'Supplier opening-balance payment',
                    'reference' => $row->reference,
                    'description' => 'Opening-balance payment to '.($row->provider ?: 'supplier').(filled($row->notes) ? ' — '.$row->notes : ''),
                    'warehouse' => 'All branches',
                    'payment_method' => $row->payment_method ?: 'Unspecified',
                    'amount' => $this->money($row->amount),
                    'created_at' => $row->created_at,
                ]);
            $outflows = $outflows->merge($providerOpeningPayments);
        }

        $customerRefunds = DB::table('payment_sale_returns')
            ->join('sale_returns', 'sale_returns.id', '=', 'payment_sale_returns.sale_return_id')
            ->leftJoin('clients', 'clients.id', '=', 'sale_returns.client_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'sale_returns.warehouse_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payment_sale_returns.payment_method_id')
            ->whereNull('payment_sale_returns.deleted_at')
            ->whereNull('sale_returns.deleted_at')
            ->where('payment_sale_returns.date', $day)
            ->whereIn('sale_returns.warehouse_id', $warehouseIds)
            ->orderBy('payment_sale_returns.id')
            ->get([
                'payment_sale_returns.id', 'payment_sale_returns.Ref as reference', 'payment_sale_returns.notes',
                'payment_sale_returns.montant as amount', 'payment_sale_returns.created_at',
                'clients.name as client', 'warehouses.name as warehouse', 'payment_methods.name as payment_method',
            ])
            ->map(fn ($row) => [
                'key' => 'customer-refund-'.$row->id,
                'type' => 'Customer refund',
                'reference' => $row->reference,
                'description' => 'Refund to '.($row->client ?: 'customer').(filled($row->notes) ? ' — '.$row->notes : ''),
                'warehouse' => $row->warehouse,
                'payment_method' => $row->payment_method ?: 'Unspecified',
                'amount' => $this->money($row->amount),
                'created_at' => $row->created_at,
            ]);
        $outflows = $outflows->merge($customerRefunds)->sortBy('created_at')->values();

        $openingBalance = (float) ($registers->opening_balance ?? 0);
        $registerCashIn = (float) ($registers->cash_in ?? 0);
        $registerCashOut = (float) ($registers->cash_out ?? 0);
        if ($registerCashOut > 0) {
            $outflows->push([
                'key' => 'register-cash-out', 'type' => 'Register cash out', 'reference' => null,
                'description' => 'Manual cash removed from registers', 'warehouse' => $warehouses->count() === 1 ? $warehouses->first()->name : 'Selected branches',
                'payment_method' => 'Cash', 'amount' => $this->money($registerCashOut), 'created_at' => null,
            ]);
        }

        $customerReceiptTotal = (float) $customerReceipts->sum('amount') + (float) $customerOpeningReceipts->sum('amount');
        $supplierReturnReceiptTotal = (float) $supplierReturnReceipts->sum('amount');
        $operatingExpenseTotal = (float) $operatingExpenseMethods->sum('amount');
        $supplierPaymentTotal = (float) $supplierPaymentMethods->sum('amount') + (float) $providerOpeningPaymentMethods->sum('amount');
        $customerRefundTotal = (float) $customerRefundMethods->sum('amount');
        $cashAvailable = $openingBalance + $customerReceiptTotal + $supplierReturnReceiptTotal + $registerCashIn;
        $totalOutflows = $operatingExpenseTotal + $supplierPaymentTotal + $customerRefundTotal + $registerCashOut;

        $methodSummary = collect();
        $addMethods = function (Collection $rows, string $direction) use (&$methodSummary): void {
            foreach ($rows as $row) {
                $method = $row['method'];
                if (! $methodSummary->has($method)) {
                    $methodSummary->put($method, ['payment_method' => $method, 'inflow' => 0.0, 'outflow' => 0.0]);
                }
                $summary = $methodSummary->get($method);
                $summary[$direction] += (float) $row['amount'];
                $methodSummary->put($method, $summary);
            }
        };
        $addMethods($customerReceipts, 'inflow');
        $addMethods($customerOpeningReceipts, 'inflow');
        $addMethods($supplierReturnReceipts, 'inflow');
        $addMethods($operatingExpenseMethods, 'outflow');
        $addMethods($supplierPaymentMethods, 'outflow');
        $addMethods($providerOpeningPaymentMethods, 'outflow');
        $addMethods($customerRefundMethods, 'outflow');
        if ($registerCashIn || $registerCashOut) {
            $addMethods(collect([['method' => 'Cash', 'amount' => $registerCashIn]]), 'inflow');
            $addMethods(collect([['method' => 'Cash', 'amount' => $registerCashOut]]), 'outflow');
        }
        $methodSummary = $methodSummary->sortKeys()->map(function ($row) {
            $row['inflow'] = $this->money($row['inflow']);
            $row['outflow'] = $this->money($row['outflow']);
            $row['net'] = $this->money($row['inflow'] - $row['outflow']);

            return $row;
        })->values();

        $saleDue = $this->outstanding('sales', $warehouseIds, 'completed');
        $saleReturnDue = $this->outstanding('sale_returns', $warehouseIds, 'completed');
        $purchaseDue = $this->outstanding('purchases', $warehouseIds, null, $providerId);
        $purchaseReturnDue = $this->outstanding('purchase_returns', $warehouseIds, 'completed', $providerId);
        $customerOpening = $includeGlobalBalances ? (float) DB::table('clients')->whereNull('deleted_at')->sum('opening_balance') : 0.0;
        $supplierOpening = $includeGlobalBalances
            ? (float) DB::table('providers')->whereNull('deleted_at')
                ->when($providerId, fn ($query) => $query->where('id', $providerId))
                ->sum('opening_balance')
            : 0.0;
        $supplierScope = $providerId
            ? (DB::table('providers')->where('id', $providerId)->value('name') ?: 'Selected supplier')
            : 'All suppliers';

        return [
            'date' => $day,
            'day_name' => $date->format('l'),
            'scope' => $warehouses->count() === 1 ? $warehouses->first()->name : 'All permitted branches',
            'supplier_scope' => $supplierScope,
            'sales_by_branch' => $salesByBranch,
            'outflows' => $outflows,
            'payment_methods' => $methodSummary,
            'totals' => [
                'opening_balance' => $this->money($openingBalance),
                'cash_difference' => $this->money($registers->difference ?? 0),
                'gross_sales' => $this->money($salesByBranch->sum('gross_sales')),
                'sale_returns' => $this->money($salesByBranch->sum('sale_returns')),
                'net_sales' => $this->money($salesByBranch->sum('net_sales')),
                'customer_receipts' => $this->money($customerReceiptTotal),
                'supplier_return_receipts' => $this->money($supplierReturnReceiptTotal),
                'register_cash_in' => $this->money($registerCashIn),
                'cash_available' => $this->money($cashAvailable),
                'operating_expenses' => $this->money($operatingExpenseTotal),
                'supplier_payments' => $this->money($supplierPaymentTotal),
                'customer_refunds' => $this->money($customerRefundTotal),
                'register_cash_out' => $this->money($registerCashOut),
                'total_outflows' => $this->money($totalOutflows),
                'calculated_closing' => $this->money($cashAvailable - $totalOutflows),
                'actual_register_closing' => $this->money($registers->closing_balance ?? 0),
                'customer_receivable' => $this->money(max(0, $customerOpening + $saleDue - $saleReturnDue)),
                'supplier_payable' => $this->money(max(0, $supplierOpening + $purchaseDue - $purchaseReturnDue)),
                'account_balances' => $includeGlobalBalances
                    ? $this->money(DB::table('accounts')->whereNull('deleted_at')->sum('balance'))
                    : null,
            ],
            'balance_scope_note' => $includeGlobalBalances
                ? 'Customer opening balances, supplier opening balances, and account balances are included.'
                : 'Branch totals exclude customer/supplier opening balances and global account balances because those records are not assigned to a branch.',
        ];
    }

    private function paymentTotals($query, string $table, string $amountColumn = 'montant'): Collection
    {
        return $query
            ->selectRaw("COALESCE(payment_methods.name, 'Unspecified') AS method")
            ->selectRaw("SUM({$table}.{$amountColumn}) AS amount")
            ->groupBy('method')
            ->get()
            ->map(fn ($row) => ['method' => $row->method, 'amount' => $this->money($row->amount)]);
    }

    private function outstanding(string $table, Collection $warehouseIds, ?string $status = null, ?int $providerId = null): float
    {
        return (float) DB::table($table)
            ->whereNull('deleted_at')
            ->whereIn('warehouse_id', $warehouseIds)
            ->when($status, fn ($query) => $query->where('statut', $status))
            ->when($providerId, fn ($query) => $query->where('provider_id', $providerId))
            ->selectRaw('COALESCE(SUM(CASE WHEN GrandTotal > paid_amount THEN GrandTotal - paid_amount ELSE 0 END), 0) AS amount')
            ->value('amount');
    }

    private function money($value): float
    {
        return round((float) $value, 2);
    }
}
