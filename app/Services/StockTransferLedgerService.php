<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\StockTransferCompany;
use App\Models\StockTransferLedgerEntry;
use App\Models\StockTransferPayment;
use App\Models\StockTransferPaymentAllocation;
use App\Models\Transfer;
use App\Models\TransferReturn;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockTransferLedgerService
{
    public function postTransfer(Transfer $transfer, User $user): Transfer
    {
        if (! Schema::hasTable('stock_transfer_ledger_entries') || ! Schema::hasColumn('transfers', 'ownership_type')) {
            return $transfer;
        }

        return DB::transaction(function () use ($transfer, $user) {
            $locked = Transfer::with(['details', 'to_warehouse.stockTransferCompany'])
                ->lockForUpdate()->findOrFail($transfer->id);
            $destination = $locked->to_warehouse;
            $isExternal = $locked->ownership_type === 'external' || $destination?->ownership_type === 'external';
            if (! $isExternal) {
                $locked->update(['ownership_type' => 'internal', 'payment_status' => 'not_applicable']);
                return $locked->fresh();
            }

            $companyId = (int) ($locked->stock_transfer_company_id ?: $destination?->stock_transfer_company_id);
            if (! $companyId || ! StockTransferCompany::whereKey($companyId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['to_warehouse' => ['The external destination warehouse must belong to an active company.']]);
            }

            StockTransferCompany::whereKey($companyId)->lockForUpdate()->firstOrFail();
            $existing = StockTransferLedgerEntry::where('idempotency_key', "transfer:{$locked->id}:debit")->first();
            if ($existing) {
                $this->refreshTransferFinancials($locked);
                return $locked->fresh();
            }

            $amount = 0.0;
            foreach ($locked->details as $detail) {
                $quantity = (float) ($detail->dispatched_quantity ?: $detail->approved_quantity ?: $detail->quantity);
                $unitPrice = (float) ($detail->unit_transfer_price ?? $detail->cost ?? 0);
                $lineTotal = round($quantity * $unitPrice, 4);
                $detail->update(['unit_transfer_price' => $unitPrice, 'transfer_total' => $lineTotal]);
                $amount += $lineTotal;
            }
            $amount = round($amount > 0 ? $amount : (float) $locked->GrandTotal, 4);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['transfer_value' => ['An external transfer must have a transfer value greater than zero.']]);
            }

            $previous = $this->companyBalance($companyId);
            StockTransferLedgerEntry::create([
                'stock_transfer_company_id' => $companyId,
                'warehouse_id' => $locked->to_warehouse_id,
                'transfer_id' => $locked->id,
                'entry_date' => $locked->date,
                'entry_type' => 'stock_transfer',
                'reference' => $locked->Ref,
                'debit' => $amount,
                'credit' => 0,
                'notes' => $locked->notes,
                'posted_by' => $user->id,
                'idempotency_key' => "transfer:{$locked->id}:debit",
                'metadata' => ['previous_balance' => $previous],
            ]);
            $locked->update([
                'ownership_type' => 'external',
                'stock_transfer_company_id' => $companyId,
                'transfer_value' => $amount,
                'previous_outstanding' => $previous,
                'outstanding_balance' => $amount,
                'payment_status' => 'unpaid',
                'financial_posted_at' => now(),
            ]);

            $this->autoAllocateAvailablePayments($locked, $user);
            $this->refreshTransferFinancials($locked);
            return $locked->fresh();
        }, 10);
    }

    public function recordPayment(array $data, User $user): StockTransferPayment
    {
        return DB::transaction(function () use ($data, $user) {
            if (! empty($data['idempotency_key'])) {
                $existing = StockTransferPayment::with(['company', 'warehouse', 'paymentMethod', 'account', 'allocations.transfer'])
                    ->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) return $existing;
            }
            $company = StockTransferCompany::whereKey($data['stock_transfer_company_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
            $warehouseId = ! empty($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;
            if ($warehouseId && ! Warehouse::whereKey($warehouseId)->where('stock_transfer_company_id', $company->id)->where('ownership_type', 'external')->exists()) {
                throw ValidationException::withMessages(['warehouse_id' => ['The selected warehouse does not belong to this company.']]);
            }

            $method = PaymentMethod::whereNull('deleted_at')->findOrFail($data['payment_method_id']);
            $account = app(PaymentAccountService::class)->resolve($method, $data['account_id'] ?? null);
            $payment = StockTransferPayment::create([
                'stock_transfer_company_id' => $company->id,
                'warehouse_id' => $warehouseId,
                'payment_date' => $data['payment_date'],
                'amount' => round((float) $data['amount'], 4),
                'payment_method_id' => $method->id,
                'account_id' => $account?->id,
                'reference' => 'STP-TMP-'.Str::uuid(),
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'external_reference' => ($externalReference = trim((string) ($data['external_reference'] ?? ''))) !== '' ? $externalReference : null,
                'remarks' => $data['remarks'] ?? null,
                'received_by' => $user->id,
                'status' => 'posted',
            ]);
            $payment->update(['reference' => 'STP-'.date('Y').'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT)]);

            StockTransferLedgerEntry::create([
                'stock_transfer_company_id' => $company->id,
                'warehouse_id' => $warehouseId,
                'stock_transfer_payment_id' => $payment->id,
                'entry_date' => $payment->payment_date,
                'entry_type' => 'payment_received',
                'reference' => $payment->reference,
                'debit' => 0,
                'credit' => $payment->amount,
                'notes' => $payment->remarks,
                'posted_by' => $user->id,
                'idempotency_key' => "payment:{$payment->id}:credit",
                'metadata' => ['external_reference' => $payment->external_reference, 'payment_method_id' => $method->id],
            ]);
            if ($account) Account::whereKey($account->id)->lockForUpdate()->increment('balance', $payment->amount);

            $allocations = collect($data['allocations'] ?? [])->filter(fn ($row) => (float) ($row['amount'] ?? 0) > 0)->values()->all();
            if ($allocations) {
                foreach ($allocations as $allocation) {
                    $this->allocate($payment, (int) $allocation['transfer_id'], (float) $allocation['amount'], $user, 'payment');
                }
            } elseif (! empty($data['transfer_id'])) {
                $this->allocate($payment, (int) $data['transfer_id'], min($payment->amount, $this->transferRemaining((int) $data['transfer_id'])), $user, 'payment');
            } elseif (($data['auto_allocate'] ?? true) === true) {
                $this->autoAllocatePayment($payment, $user);
            }

            return $payment->fresh(['company', 'warehouse', 'paymentMethod', 'account', 'allocations.transfer']);
        }, 10);
    }

    public function allocate(StockTransferPayment $payment, int $transferId, float $amount, User $user, string $type = 'advance'): StockTransferPaymentAllocation
    {
        $payment = StockTransferPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
        $transfer = Transfer::whereKey($transferId)->lockForUpdate()->firstOrFail();
        if ($payment->status !== 'posted' || $transfer->ownership_type !== 'external' || (int) $transfer->stock_transfer_company_id !== (int) $payment->stock_transfer_company_id) {
            throw ValidationException::withMessages(['transfer_id' => ['The payment and transfer must be posted for the same external company.']]);
        }
        $amount = round($amount, 4);
        $available = round((float) $payment->amount - (float) $payment->allocations()->sum('amount'), 4);
        $remaining = $this->transferRemaining($transfer->id);
        if ($amount <= 0 || $amount > $available + 0.0001 || $amount > $remaining + 0.0001) {
            throw ValidationException::withMessages(['amount' => ['Allocation must be positive and cannot exceed the unallocated payment or transfer balance.']]);
        }

        $allocation = StockTransferPaymentAllocation::where('stock_transfer_payment_id', $payment->id)->where('transfer_id', $transfer->id)->lockForUpdate()->first();
        if ($allocation) {
            $allocation->amount = round((float) $allocation->amount + $amount, 4);
            $allocation->allocation_type = $type;
            $allocation->allocated_by = $user->id;
            $allocation->save();
        } else {
            $allocation = StockTransferPaymentAllocation::create([
                'stock_transfer_payment_id' => $payment->id,
                'transfer_id' => $transfer->id,
                'amount' => $amount,
                'allocation_type' => $type,
                'allocated_by' => $user->id,
            ]);
        }

        StockTransferLedgerEntry::updateOrCreate(
            ['idempotency_key' => "allocation:{$allocation->id}"],
            [
                'stock_transfer_company_id' => $payment->stock_transfer_company_id,
                'warehouse_id' => $transfer->to_warehouse_id,
                'transfer_id' => $transfer->id,
                'stock_transfer_payment_id' => $payment->id,
                'entry_date' => now()->toDateString(),
                'entry_type' => $type === 'advance' ? 'advance_adjustment' : 'payment_allocation',
                'reference' => $payment->reference.' / '.$transfer->Ref,
                'debit' => 0, 'credit' => 0,
                'notes' => 'Allocation only; payment credit is recorded once on receipt.',
                'posted_by' => $user->id,
                'metadata' => ['allocated_amount' => (float) $allocation->amount],
            ]
        );
        $this->refreshTransferFinancials($transfer);
        return $allocation->fresh();
    }

    public function postReturnCredit(TransferReturn $return, User $user): ?StockTransferLedgerEntry
    {
        if (! Schema::hasTable('stock_transfer_ledger_entries')) return null;
        return DB::transaction(function () use ($return, $user) {
            $locked = TransferReturn::with(['transfer.details', 'details.transferDetail'])->lockForUpdate()->findOrFail($return->id);
            $transfer = Transfer::whereKey($locked->transfer_id)->lockForUpdate()->firstOrFail();
            if ($transfer->ownership_type !== 'external' || ! $transfer->stock_transfer_company_id) return null;
            $amount = round($locked->details->sum(function ($row) {
                $price = (float) ($row->transferDetail?->unit_transfer_price ?? $row->transferDetail?->cost ?? 0);
                return (float) $row->quantity * $price;
            }), 4);
            if ($amount <= 0) return null;

            $entry = StockTransferLedgerEntry::firstOrCreate(
                ['idempotency_key' => "return:{$locked->id}:credit"],
                [
                    'stock_transfer_company_id' => $transfer->stock_transfer_company_id,
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'transfer_id' => $transfer->id,
                    'transfer_return_id' => $locked->id,
                    'entry_date' => $locked->received_at?->toDateString() ?: now()->toDateString(),
                    'entry_type' => 'return_credit',
                    'reference' => $locked->reference,
                    'debit' => 0, 'credit' => min($amount, $this->transferRemaining($transfer->id)),
                    'notes' => $locked->note,
                    'posted_by' => $user->id,
                ]
            );
            $this->refreshTransferFinancials($transfer);
            return $entry;
        }, 10);
    }

    public function createOpeningEntry(StockTransferCompany $company, User $user): void
    {
        $amount = round((float) $company->opening_balance, 4);
        if (abs($amount) < 0.0001) return;
        StockTransferLedgerEntry::firstOrCreate(
            ['idempotency_key' => "company:{$company->id}:opening"],
            [
                'stock_transfer_company_id' => $company->id,
                'entry_date' => now()->toDateString(),
                'entry_type' => 'opening_balance',
                'reference' => 'OPENING-'.$company->id,
                'debit' => max($amount, 0), 'credit' => max(-$amount, 0),
                'notes' => 'Opening balance', 'posted_by' => $user->id,
            ]
        );
    }

    public function companyBalance(int $companyId): float
    {
        $totals = StockTransferLedgerEntry::where('stock_transfer_company_id', $companyId)
            ->selectRaw('COALESCE(SUM(debit),0) debit_total, COALESCE(SUM(credit),0) credit_total')->first();
        return round((float) $totals->debit_total - (float) $totals->credit_total, 4);
    }

    public function transferRemaining(int $transferId): float
    {
        $transfer = Transfer::findOrFail($transferId);
        $allocated = (float) StockTransferPaymentAllocation::where('transfer_id', $transferId)->sum('amount');
        $credits = (float) StockTransferLedgerEntry::where('transfer_id', $transferId)->where('entry_type', 'return_credit')->sum('credit');
        return max(0, round((float) $transfer->transfer_value - $allocated - $credits, 4));
    }

    public function refreshTransferFinancials(Transfer $transfer): void
    {
        $allocated = (float) StockTransferPaymentAllocation::where('transfer_id', $transfer->id)->sum('amount');
        $advance = (float) StockTransferPaymentAllocation::where('transfer_id', $transfer->id)->where('allocation_type', 'advance')->sum('amount');
        $remaining = $this->transferRemaining($transfer->id);
        $status = $remaining <= 0.0001 ? 'paid' : (($allocated > 0 || $remaining < (float) $transfer->transfer_value) ? 'partially_paid' : 'unpaid');
        $transfer->update([
            'advance_applied' => round($advance, 4),
            'paid_amount' => round($allocated, 4),
            'outstanding_balance' => $remaining,
            'payment_status' => $status,
        ]);
    }

    private function autoAllocatePayment(StockTransferPayment $payment, User $user): void
    {
        $remaining = round((float) $payment->amount - (float) $payment->allocations()->sum('amount'), 4);
        $transfers = Transfer::where('stock_transfer_company_id', $payment->stock_transfer_company_id)
            ->where('ownership_type', 'external')->whereNotNull('financial_posted_at')
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])->orderBy('date')->orderBy('id')->lockForUpdate()->get();
        foreach ($transfers as $transfer) {
            if ($remaining <= 0.0001) break;
            $amount = min($remaining, $this->transferRemaining($transfer->id));
            if ($amount > 0) {
                $this->allocate($payment, $transfer->id, $amount, $user, $payment->payment_date->lt($transfer->date) ? 'advance' : 'payment');
                $remaining = round($remaining - $amount, 4);
            }
        }
    }

    private function autoAllocateAvailablePayments(Transfer $transfer, User $user): void
    {
        $payments = StockTransferPayment::where('stock_transfer_company_id', $transfer->stock_transfer_company_id)
            ->where('status', 'posted')->orderBy('payment_date')->orderBy('id')->lockForUpdate()->get();
        foreach ($payments as $payment) {
            $available = round((float) $payment->amount - (float) $payment->allocations()->sum('amount'), 4);
            $remaining = $this->transferRemaining($transfer->id);
            if ($available > 0.0001 && $remaining > 0.0001) {
                $this->allocate($payment, $transfer->id, min($available, $remaining), $user, 'advance');
            }
            if ($this->transferRemaining($transfer->id) <= 0.0001) break;
        }
    }
}
