<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyReportService
{
    public function build(Carbon $date, Collection $warehouses, bool $includeGlobalBalances, ?int $providerId = null): array
    {
        return $this->buildRange($date, $date, $warehouses, $includeGlobalBalances, $providerId);
    }

    public function buildRange(Carbon $startDate, Carbon $endDate, Collection $warehouses, bool $includeGlobalBalances, ?int $providerId = null): array
    {
        $startDate = $startDate->copy()->startOfDay();
        $endDate = $endDate->copy()->endOfDay();
        $startDay = $startDate->toDateString();
        $endDay = $endDate->toDateString();
        $warehouseIds = $warehouses->pluck('id')->map(fn ($id) => (int) $id)->values();

        $sales = DB::table('sales')
            ->whereNull('deleted_at')
            ->whereBetween('date', [$startDay, $endDay])
            ->where('statut', 'completed')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('warehouse_id, SUM(GrandTotal) AS amount')
            ->groupBy('warehouse_id')
            ->pluck('amount', 'warehouse_id');
        $returns = DB::table('sale_returns')
            ->whereNull('deleted_at')
            ->whereBetween('date', [$startDay, $endDay])
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
            ->whereBetween('opened_at', [$startDate, $endDate])
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
                ->whereBetween('payment_sales.date', [$startDay, $endDay])
                ->whereIn('sales.warehouse_id', $warehouseIds),
            'payment_sales'
        );
        $customerOpeningReceipts = $includeGlobalBalances
            ? $this->paymentTotals(
                DB::table('client_opening_balance_payments')
                    ->leftJoin('payment_methods', 'payment_methods.id', '=', 'client_opening_balance_payments.payment_method_id')
                    ->whereNull('client_opening_balance_payments.deleted_at')
                    ->whereBetween('client_opening_balance_payments.date', [$startDay, $endDay]),
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
                ->whereBetween('payment_purchase_returns.date', [$startDay, $endDay])
                ->when($providerId, fn ($query) => $query->where('purchase_returns.provider_id', $providerId))
                ->whereIn('purchase_returns.warehouse_id', $warehouseIds),
            'payment_purchase_returns'
        );
        $operatingExpenseMethods = $this->paymentTotals(
            DB::table('expenses')
                ->leftJoin('payment_methods', 'payment_methods.id', '=', 'expenses.payment_method_id')
                ->whereNull('expenses.deleted_at')
                ->whereBetween('expenses.date', [$startDay, $endDay])
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
                ->whereBetween('payment_purchases.date', [$startDay, $endDay])
                ->when($providerId, fn ($query) => $query->where('purchases.provider_id', $providerId))
                ->whereIn('purchases.warehouse_id', $warehouseIds),
            'payment_purchases'
        );
        $providerOpeningPaymentMethods = $includeGlobalBalances
            ? $this->paymentTotals(
                DB::table('provider_opening_balance_payments')
                    ->leftJoin('payment_methods', 'payment_methods.id', '=', 'provider_opening_balance_payments.payment_method_id')
                    ->whereNull('provider_opening_balance_payments.deleted_at')
                    ->whereBetween('provider_opening_balance_payments.date', [$startDay, $endDay])
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
                ->whereBetween('payment_sale_returns.date', [$startDay, $endDay])
                ->whereIn('sale_returns.warehouse_id', $warehouseIds),
            'payment_sale_returns'
        );

        $outflows = collect();
        $expenses = DB::table('expenses')
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'expenses.warehouse_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'expenses.payment_method_id')
            ->whereNull('expenses.deleted_at')
            ->whereBetween('expenses.date', [$startDay, $endDay])
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
            ->whereBetween('payment_purchases.date', [$startDay, $endDay])
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
                ->whereBetween('provider_opening_balance_payments.date', [$startDay, $endDay])
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
            ->whereBetween('payment_sale_returns.date', [$startDay, $endDay])
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
            'date' => $startDay,
            'start_date' => $startDay,
            'end_date' => $endDay,
            'day_name' => $startDay === $endDay
                ? $startDate->format('l')
                : ($startDate->diffInDays($endDate->copy()->startOfDay()) + 1).' days',
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

    public function branchDetails(Carbon $startDate, Carbon $endDate, int $warehouseId): array
    {
        $startDate = $startDate->copy()->startOfDay();
        $endDate = $endDate->copy()->endOfDay();
        $startDay = $startDate->toDateString();
        $endDay = $endDate->toDateString();

        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->whereNull('deleted_at')->first();
        abort_unless($warehouse, 404);

        $transactionRows = DB::table('sales')
            ->leftJoin('clients', 'clients.id', '=', 'sales.client_id')
            ->leftJoin('users', 'users.id', '=', 'sales.user_id')
            ->leftJoin('sales_agents', 'sales_agents.id', '=', 'sales.sales_agent_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.warehouse_id', $warehouseId)
            ->whereBetween('sales.date', [$startDay, $endDay])
            ->orderBy('sales.date')
            ->orderBy('sales.time')
            ->orderBy('sales.id')
            ->get([
                'sales.id', 'sales.Ref as reference', 'sales.date', 'sales.time', 'sales.created_at',
                'sales.statut', 'sales.payment_statut', 'sales.GrandTotal as total',
                'sales.paid_amount', 'clients.name as customer_name', 'clients.phone as customer_phone',
                'clients.adresse as customer_address', 'users.username as created_by',
                'sales_agents.name as sales_agent',
            ]);

        // Payments are selected by receipt date, not by invoice date. This is
        // what makes a payment collected today against last month's invoice
        // visible in today's branch report.
        $paymentsInRange = DB::table('payment_sales')
            ->join('sales', 'sales.id', '=', 'payment_sales.sale_id')
            ->leftJoin('clients', 'clients.id', '=', 'sales.client_id')
            ->leftJoin('users as payment_users', 'payment_users.id', '=', 'payment_sales.user_id')
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'payment_sales.payment_method_id')
            ->whereNull('payment_sales.deleted_at')
            ->whereNull('sales.deleted_at')
            ->where('sales.warehouse_id', $warehouseId)
            ->whereBetween('payment_sales.date', [$startDay, $endDay])
            ->orderBy('payment_sales.date')
            ->orderBy('payment_sales.created_at')
            ->orderBy('payment_sales.id')
            ->get([
                'payment_sales.id', 'payment_sales.sale_id', 'payment_sales.Ref as receipt_reference',
                'payment_sales.date as receipt_date', 'payment_sales.created_at as receipt_created_at',
                'payment_sales.montant as amount', 'payment_sales.allocation_type',
                'payment_sales.source_sale_ref', 'sales.Ref as sale_reference', 'sales.date as sale_date',
                'sales.statut', 'sales.GrandTotal as sale_total', 'sales.paid_amount',
                'clients.name as customer_name', 'clients.phone as customer_phone',
                'clients.adresse as customer_address', 'payment_users.username as received_by',
                'payment_methods.name as payment_method',
            ]);

        $relevantSaleIds = $transactionRows->pluck('id')
            ->merge($paymentsInRange->pluck('sale_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $itemsBySale = collect();
        if ($relevantSaleIds->isNotEmpty()) {
            $itemsBySale = DB::table('sale_details')
                ->join('products', 'products.id', '=', 'sale_details.product_id')
                ->leftJoin('product_variants', 'product_variants.id', '=', 'sale_details.product_variant_id')
                ->whereIn('sale_details.sale_id', $relevantSaleIds)
                ->orderBy('sale_details.id')
                ->get([
                    'sale_details.sale_id', 'sale_details.product_id', 'sale_details.product_variant_id',
                    'sale_details.quantity', 'sale_details.price', 'sale_details.total',
                    'products.name as product_name', 'products.code as product_code',
                    'product_variants.name as variant_name',
                ])
                ->map(function ($row) {
                    $model = $row->variant_name
                        ? '['.$row->variant_name.'] '.$row->product_name
                        : $row->product_name;

                    return [
                        'sale_id' => (int) $row->sale_id,
                        'product_id' => (int) $row->product_id,
                        'product_variant_id' => $row->product_variant_id ? (int) $row->product_variant_id : null,
                        'model' => $model,
                        'code' => $row->product_code,
                        'quantity' => (float) $row->quantity,
                        'unit_price' => $this->money($row->price),
                        'line_total' => $this->money($row->total),
                    ];
                })
                ->groupBy('sale_id');
        }

        $saleTotals = collect();
        foreach ($transactionRows as $sale) {
            $saleTotals->put((int) $sale->id, (float) $sale->total);
        }
        foreach ($paymentsInRange as $payment) {
            $saleTotals->put((int) $payment->sale_id, (float) $payment->sale_total);
        }

        $remainingAfterPayment = [];
        if ($relevantSaleIds->isNotEmpty()) {
            $runningPaid = [];
            $paymentHistory = DB::table('payment_sales')
                ->whereNull('deleted_at')
                ->whereIn('sale_id', $relevantSaleIds)
                ->orderBy('date')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'sale_id', 'montant']);

            foreach ($paymentHistory as $payment) {
                $saleId = (int) $payment->sale_id;
                $runningPaid[$saleId] = ($runningPaid[$saleId] ?? 0) + (float) $payment->montant;
                $remainingAfterPayment[(int) $payment->id] = $this->money(max(
                    0,
                    (float) $saleTotals->get($saleId, 0) - $runningPaid[$saleId]
                ));
            }
        }

        $classifyReceipt = function ($payment): array {
            $allocationType = strtolower((string) ($payment->allocation_type ?? ''));
            if ($allocationType === 'previous') {
                return ['key' => 'previous_balance', 'label' => 'Previous balance payment'];
            }
            if ($allocationType === 'advance' || $payment->statut === 'ordered') {
                return ['key' => 'advance', 'label' => 'Advance payment'];
            }
            if ((string) $payment->receipt_date > (string) $payment->sale_date) {
                return ['key' => 'previous_balance', 'label' => 'Previous balance payment'];
            }

            return ['key' => 'sale_payment', 'label' => 'Sale payment'];
        };

        $receipts = $paymentsInRange->map(function ($payment) use ($classifyReceipt, $itemsBySale, $remainingAfterPayment) {
            $type = $classifyReceipt($payment);
            $items = collect($itemsBySale->get((int) $payment->sale_id, []))->values();

            return [
                'id' => (int) $payment->id,
                'sale_id' => (int) $payment->sale_id,
                'receipt_reference' => $payment->receipt_reference,
                'order_number' => $payment->sale_reference,
                'receipt_date' => $payment->receipt_date,
                'receipt_time' => $payment->receipt_created_at ? Carbon::parse($payment->receipt_created_at)->format('h:i A') : '-',
                'sale_date' => $payment->sale_date,
                'transaction_type' => $payment->statut === 'ordered' ? 'Order' : 'Sale',
                'customer_name' => $payment->customer_name ?: 'Walk-in customer',
                'customer_phone' => $payment->customer_phone,
                'customer_address' => $payment->customer_address,
                'received_by' => $payment->received_by ?: 'Unassigned',
                'payment_method' => $payment->payment_method ?: 'Unspecified',
                'receipt_type' => $type['label'],
                'receipt_type_key' => $type['key'],
                'amount' => $this->money($payment->amount),
                'remaining_after_receipt' => $remainingAfterPayment[(int) $payment->id]
                    ?? $this->money(max(0, (float) $payment->sale_total - (float) $payment->paid_amount)),
                'current_remaining' => $this->money(max(0, (float) $payment->sale_total - (float) $payment->paid_amount)),
                'items' => $items,
                'quantity' => $this->money($items->sum('quantity')),
            ];
        })->values();

        $receiptsBySale = $receipts->groupBy('sale_id');
        $transactions = $transactionRows->map(function ($sale) use ($itemsBySale, $receiptsBySale) {
            $items = collect($itemsBySale->get((int) $sale->id, []))->values();
            $saleReceipts = collect($receiptsBySale->get((int) $sale->id, []));

            return [
                'id' => (int) $sale->id,
                'order_number' => $sale->reference,
                'date' => $sale->date,
                'time' => $sale->time
                    ? Carbon::parse($sale->time)->format('h:i A')
                    : ($sale->created_at ? Carbon::parse($sale->created_at)->format('h:i A') : '-'),
                'transaction_type' => $sale->statut === 'ordered' ? 'Order' : 'Sale',
                'status' => $sale->statut,
                'payment_status' => $sale->payment_statut,
                'customer_name' => $sale->customer_name ?: 'Walk-in customer',
                'customer_phone' => $sale->customer_phone,
                'customer_address' => $sale->customer_address,
                'sold_by' => $sale->sales_agent ?: ($sale->created_by ?: 'Unassigned'),
                'items' => $items,
                'quantity' => $this->money($items->sum('quantity')),
                'total' => $this->money($sale->total),
                'paid_to_date' => $this->money($sale->paid_amount),
                'received_in_period' => $this->money($saleReceipts->sum('amount')),
                'advance_received' => $this->money($saleReceipts->where('receipt_type_key', 'advance')->sum('amount')),
                'previous_balance_received' => $this->money($saleReceipts->where('receipt_type_key', 'previous_balance')->sum('amount')),
                'remaining_balance' => $this->money(max(0, (float) $sale->total - (float) $sale->paid_amount)),
            ];
        })->values();

        $shownSaleIds = $relevantSaleIds->all();
        $currentOutstanding = (float) DB::table('sales')
            ->whereNull('deleted_at')
            ->whereIn('id', $shownSaleIds ?: [0])
            ->selectRaw('COALESCE(SUM(CASE WHEN GrandTotal > paid_amount THEN GrandTotal - paid_amount ELSE 0 END), 0) AS amount')
            ->value('amount');

        return [
            'warehouse_id' => $warehouseId,
            'warehouse' => $warehouse->name,
            'start_date' => $startDay,
            'end_date' => $endDay,
            'transactions' => $transactions,
            'receipts' => $receipts,
            'totals' => [
                'transaction_count' => $transactions->count(),
                'quantity' => $this->money($transactions->sum('quantity')),
                'sales_value' => $this->money($transactions->where('transaction_type', 'Sale')->sum('total')),
                'orders_value' => $this->money($transactions->where('transaction_type', 'Order')->sum('total')),
                'payments_received' => $this->money($receipts->sum('amount')),
                'advance_received' => $this->money($receipts->where('receipt_type_key', 'advance')->sum('amount')),
                'previous_balance_received' => $this->money($receipts->where('receipt_type_key', 'previous_balance')->sum('amount')),
                'sale_payments_received' => $this->money($receipts->where('receipt_type_key', 'sale_payment')->sum('amount')),
                'current_outstanding' => $this->money($currentOutstanding),
            ],
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
