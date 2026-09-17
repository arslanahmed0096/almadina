<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Unit;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerLedgerPdfService
{
    public function build(Client $client): array
    {
        $sales = Sale::query()
            ->whereNull('deleted_at')
            ->where('client_id', $client->id)
            ->whereNotIn('statut', ['cancelled', 'canceled'])
            ->with(['details.product:id,name,code', 'details.productVariant:id,name,code'])
            ->get();

        $returns = SaleReturn::query()
            ->whereNull('deleted_at')
            ->where('client_id', $client->id)
            ->with(['details.product:id,name,code', 'sale:id,Ref'])
            ->get();

        $salePayments = DB::table('payment_sales')
            ->join('sales', 'payment_sales.sale_id', '=', 'sales.id')
            ->whereNull('payment_sales.deleted_at')
            ->whereNull('sales.deleted_at')
            ->where('sales.client_id', $client->id)
            ->get([
                'payment_sales.id', 'payment_sales.date', 'payment_sales.created_at',
                'payment_sales.Ref', 'payment_sales.montant',
                'payment_sales.allocation_reference', 'payment_sales.source_sale_ref',
                'payment_sales.allocation_type', 'payment_sales.allocation_sequence',
                'sales.Ref as target_sale_ref',
            ]);

        $openingPayments = DB::table('client_opening_balance_payments')
            ->whereNull('deleted_at')
            ->where('client_id', $client->id)
            ->get(['id', 'date', 'created_at', 'Ref', 'montant']);

        $returnPayments = DB::table('payment_sale_returns')
            ->join('sale_returns', 'payment_sale_returns.sale_return_id', '=', 'sale_returns.id')
            ->whereNull('payment_sale_returns.deleted_at')
            ->whereNull('sale_returns.deleted_at')
            ->where('sale_returns.client_id', $client->id)
            ->get([
                'payment_sale_returns.id', 'payment_sale_returns.date',
                'payment_sale_returns.created_at', 'payment_sale_returns.Ref',
                'payment_sale_returns.montant', 'sale_returns.Ref as return_ref',
            ]);

        $unitIds = $sales->flatMap(fn ($sale) => $sale->details->pluck('sale_unit_id'))
            ->merge($returns->flatMap(fn ($return) => $return->details->pluck('sale_unit_id')))
            ->filter()->unique()->values();
        $unitNames = Unit::whereIn('id', $unitIds)
            ->pluck('ShortName', 'id');

        $openingBalance = round(
            (float) ($client->opening_balance ?? 0) + (float) $openingPayments->sum('montant'),
            2
        );
        $entries = collect();

        foreach ($sales as $sale) {
            $lines = $sale->details->map(function ($detail) use ($unitNames) {
                $product = $detail->product;
                $variant = $detail->productVariant;
                $name = trim((string) ($product->name ?? 'Deleted product'));
                if ($variant && $variant->name) {
                    $name .= ' - '.$variant->name;
                }

                return [
                    'name' => $name,
                    'unit' => (string) ($unitNames[$detail->sale_unit_id] ?? ''),
                    'quantity' => (float) $detail->quantity,
                    'rate' => (float) $detail->price,
                    'amount' => (float) $detail->total,
                ];
            })->all();

            $entries->push([
                'date' => $sale->date ?: $sale->created_at,
                'sort_time' => $sale->time ?: '00:00:00',
                'sort_rank' => 1,
                'sort_id' => (int) $sale->id,
                'type' => 'Sale',
                'ref' => (string) $sale->Ref,
                'narration' => 'Credit sale invoice '.$sale->Ref,
                'debit' => round((float) $sale->GrandTotal, 2),
                'credit' => 0.0,
                'lines' => $lines,
                'items_total' => round((float) $sale->details->sum('total'), 2),
                'discount' => (float) $sale->discount,
                'discount_method' => (string) ($sale->discount_Method ?? '2'),
                'tax' => (float) $sale->TaxNet,
                'shipping' => (float) $sale->shipping,
            ]);
        }

        foreach ($returns as $return) {
            $lines = $return->details->map(function ($detail) use ($unitNames) {
                return [
                    'name' => (string) ($detail->product->name ?? 'Deleted product'),
                    'unit' => (string) ($unitNames[$detail->sale_unit_id] ?? ''),
                    'quantity' => (float) $detail->quantity,
                    'rate' => (float) $detail->price,
                    'amount' => (float) $detail->total,
                ];
            })->all();

            $entries->push([
                'date' => $return->date ?: $return->created_at,
                'sort_time' => $return->time ?: '00:00:00',
                'sort_rank' => 2,
                'sort_id' => (int) $return->id,
                'type' => 'Return',
                'ref' => (string) $return->Ref,
                'narration' => 'Sale return '.$return->Ref.($return->sale ? ' against '.$return->sale->Ref : ''),
                'debit' => 0.0,
                'credit' => round((float) $return->GrandTotal, 2),
                'lines' => $lines,
                'items_total' => round((float) $return->details->sum('total'), 2),
                'discount' => (float) $return->discount,
                'discount_method' => '2',
                'tax' => (float) $return->TaxNet,
                'shipping' => (float) $return->shipping,
            ]);
        }

        foreach ($salePayments as $payment) {
            $narration = 'Payment received, applied to '.$payment->target_sale_ref;
            if ($payment->allocation_reference) {
                $narration .= '; allocation '.$payment->allocation_reference
                    .' from '.$payment->source_sale_ref
                    .' ('.($payment->allocation_type === 'previous' ? 'previous invoice' : 'current invoice').')';
            }
            $entries->push($this->paymentEntry($payment, 'Receipt', $narration, false));
        }

        foreach ($openingPayments as $payment) {
            $entries->push($this->paymentEntry(
                $payment, 'Receipt', 'Payment received against opening balance', false
            ));
        }

        foreach ($returnPayments as $payment) {
            $entries->push($this->paymentEntry(
                $payment, 'Refund', 'Refund paid against sale return '.$payment->return_ref, true
            ));
        }

        $entries = $entries->sort(function (array $a, array $b) {
            return [
                (string) Carbon::parse($a['date'])->format('Y-m-d'),
                $a['sort_rank'], $a['sort_time'], $a['sort_id'],
            ] <=> [
                (string) Carbon::parse($b['date'])->format('Y-m-d'),
                $b['sort_rank'], $b['sort_time'], $b['sort_id'],
            ];
        })->values();

        $balance = $openingBalance;
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $entries = $entries->map(function (array $entry) use (&$balance, &$totalDebit, &$totalCredit) {
            $totalDebit = round($totalDebit + $entry['debit'], 2);
            $totalCredit = round($totalCredit + $entry['credit'], 2);
            $balance = round($balance + $entry['debit'] - $entry['credit'], 2);
            $entry['balance'] = $balance;
            return $entry;
        });

        return [
            'opening_balance' => $openingBalance,
            'entries' => $entries,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => $balance,
            'from_date' => $entries->isNotEmpty()
                ? Carbon::parse($entries->first()['date'])->format('d M Y') : null,
            'to_date' => $entries->isNotEmpty()
                ? Carbon::parse($entries->last()['date'])->format('d M Y') : null,
        ];
    }

    private function paymentEntry(object $payment, string $type, string $narration, bool $debit): array
    {
        $amount = round((float) $payment->montant, 2);

        return [
            'date' => $payment->date ?: $payment->created_at,
            'sort_time' => $payment->created_at
                ? Carbon::parse($payment->created_at)->format('H:i:s') : '12:00:00',
            'sort_rank' => $debit ? 4 : 3,
            'sort_id' => (int) $payment->id,
            'type' => $type,
            'ref' => (string) $payment->Ref,
            'narration' => $narration,
            'debit' => $debit ? $amount : 0.0,
            'credit' => $debit ? 0.0 : $amount,
            'lines' => [],
            'items_total' => 0.0,
            'discount' => 0.0,
            'discount_method' => '2',
            'tax' => 0.0,
            'shipping' => 0.0,
        ];
    }
}
