<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\PaymentPurchase;
use App\Models\Provider;
use App\Models\ProviderPaymentOpeningBalance;
use App\Models\Purchase;
use App\Models\SupplierPayment;
use App\Models\UserWarehouse;
use App\Services\PaymentAccountService;
use App\Services\SupplierPaymentAllocationService;
use App\Services\SupplierCreditTermsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierPaymentController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);

        $query = SupplierPayment::with(['provider:id,name', 'paymentMethod:id,name', 'account:id,account_name,account_num', 'user:id,username'])
            ->with(['allocations' => fn ($q) => $q->with('purchase:id,Ref,date,GrandTotal')->whereNull('deleted_at')->orderBy('purchase_id')])
            ->with('openingBalanceAllocation')
            ->orderByDesc('payment_date')->orderByDesc('id');

        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->integer('provider_id'));
        }

        return response()->json([
            'payments' => $query->paginate((int) $request->input('limit', 20)),
            'providers' => Provider::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'payment_methods' => PaymentMethod::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::whereNull('deleted_at')->orderBy('account_name')->get(['id', 'account_name', 'account_num', 'account_type', 'balance']),
        ]);
    }

    public function outstanding(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', PaymentPurchase::class);
        $data = $request->validate(['provider_id' => ['required', 'integer', 'exists:providers,id']]);
        $provider = Provider::whereNull('deleted_at')->findOrFail((int) $data['provider_id']);
        $purchases = $this->outstandingQuery($request, (int) $data['provider_id'])->get();
        $creditTerms = app(SupplierCreditTermsService::class);
        $purchaseBalance = 0;
        $overdueBalance = 0;
        $invoices = $purchases->map(function ($purchase) use ($creditTerms, &$purchaseBalance, &$overdueBalance) {
            $outstanding = round(max(0, (float) $purchase->GrandTotal - (float) $purchase->paid_amount), 2);
            $purchaseBalance += $outstanding;
            foreach ($creditTerms->outstanding($purchase, Carbon::today()) as $term) {
                if (Carbon::parse($term['due_date'])->lt(Carbon::today())) {
                    $overdueBalance += $term['outstanding'];
                }
            }

            return [
                'id' => $purchase->id,
                'reference' => $purchase->Ref,
                'date' => $purchase->date,
                'grand_total' => (float) $purchase->GrandTotal,
                'paid_amount' => (float) $purchase->paid_amount,
                'outstanding' => $outstanding,
                'warehouse' => optional($purchase->warehouse)->name,
            ];
        })->values();
        $openingBalance = round(max(0, (float) $provider->opening_balance), 2);

        return response()->json([
            'invoices' => $invoices,
            'opening_balance' => $openingBalance,
            'purchase_credit_balance' => round($purchaseBalance, 2),
            'overdue_balance' => round($overdueBalance, 2),
            'total_outstanding' => round($openingBalance + $purchaseBalance, 2),
        ]);
    }

    public function store(Request $request, PaymentAccountService $accountService, SupplierPaymentAllocationService $allocationService)
    {
        $this->authorizeForUser($request->user('api'), 'create', PaymentPurchase::class);
        $data = $request->validate([
            'provider_id' => ['required', 'integer', 'exists:providers,id'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'bank_name' => ['nullable', 'string', 'max:191'],
            'bank_account_number' => ['nullable', 'string', 'max:191'],
            'transaction_reference' => ['nullable', 'string', 'max:191'],
            'transfer_date' => ['nullable', 'date_format:Y-m-d'],
            'cheque_number' => ['nullable', 'string', 'max:191'],
            'cheque_date' => ['nullable', 'date_format:Y-m-d'],
            'cheque_status' => ['nullable', 'in:pending,cleared,bounced,cancelled'],
            'clearance_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
        ]);

        $method = PaymentMethod::findOrFail($data['payment_method_id']);
        $methodName = strtolower((string) $method->name);
        $isCheque = str_contains($methodName, 'cheque') || str_contains($methodName, 'check');
        $isBank = str_contains($methodName, 'bank') || $isCheque;
        if ($isCheque && (empty($data['cheque_number']) || empty($data['cheque_date']))) {
            throw ValidationException::withMessages(['cheque_number' => ['Cheque number and cheque date are required.']]);
        }
        if ($isBank && empty($data['bank_account_number']) && empty($data['account_id'])) {
            throw ValidationException::withMessages(['bank_account_number' => ['Select a bank account or enter the bank account number.']]);
        }
        $account = $accountService->resolve($method, $data['account_id'] ?? null);

        $payment = DB::transaction(function () use ($request, $data, $method, $account, $allocationService) {
            $provider = Provider::whereNull('deleted_at')->lockForUpdate()->findOrFail($data['provider_id']);
            $invoices = $this->outstandingQuery($request, (int) $data['provider_id'])->lockForUpdate()->get();
            $amount = round((float) $data['amount'], 2);
            $allocation = $allocationService->planCombined($invoices, $amount, (float) $provider->opening_balance);
            $openingBalanceAmount = $allocation['opening_balance_amount'];
            $allocationPlan = $allocation['invoices'];
            if ($openingBalanceAmount > 0) {
                $this->authorizeForUser($request->user('api'), 'pay_supplier_due', Provider::class);
            }

            $payment = SupplierPayment::create(array_merge($data, [
                'reference' => 'SP-'.now()->format('YmdHis').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
                'user_id' => Auth::id(),
                'account_id' => $account?->id,
                'cheque_status' => $data['cheque_status'] ?? (str_contains(strtolower($method->name), 'cheque') ? 'pending' : null),
                'status' => 'posted',
                'opening_balance_amount' => $openingBalanceAmount,
            ]));

            if ($openingBalanceAmount > 0) {
                $provider->update([
                    'opening_balance' => round((float) $provider->opening_balance - $openingBalanceAmount, 2),
                ]);
                ProviderPaymentOpeningBalance::create([
                    'provider_id' => $provider->id,
                    'supplier_payment_id' => $payment->id,
                    'account_id' => $account?->id,
                    'Ref' => $payment->reference.'-OB',
                    'date' => $data['payment_date'],
                    'payment_method_id' => $method->id,
                    'montant' => $openingBalanceAmount,
                    'change' => 0,
                    'notes' => $data['notes'] ?? null,
                    'user_id' => Auth::id(),
                ]);
            }

            $sequence = 1;
            foreach ($allocationPlan as $allocation) {
                $purchase = $allocation['purchase'];
                $allocated = $allocation['amount'];

                PaymentPurchase::create([
                    'purchase_id' => $purchase->id,
                    'supplier_payment_id' => $payment->id,
                    'account_id' => $account?->id,
                    'Ref' => $payment->reference.'-'.str_pad($sequence++, 2, '0', STR_PAD_LEFT),
                    'date' => $data['payment_date'],
                    'payment_method_id' => $method->id,
                    'montant' => $allocated,
                    'change' => 0,
                    'notes' => $data['notes'] ?? null,
                    'user_id' => Auth::id(),
                ]);

                $newPaid = round((float) $purchase->paid_amount + $allocated, 2);
                $purchase->update([
                    'paid_amount' => $newPaid,
                    'payment_statut' => $newPaid >= round((float) $purchase->GrandTotal, 2) ? 'paid' : 'partial',
                ]);
            }

            if ($account) {
                $lockedAccount = Account::lockForUpdate()->findOrFail($account->id);
                $lockedAccount->update(['balance' => round((float) $lockedAccount->balance - $amount, 2)]);
            }

            return $payment->load(['provider', 'paymentMethod', 'account', 'allocations.purchase', 'openingBalanceAllocation']);
        }, 5);

        return response()->json(['success' => true, 'payment' => $payment], 201);
    }

    public function destroy(Request $request, SupplierPayment $supplierPayment)
    {
        $this->authorizeForUser($request->user('api'), 'delete', PaymentPurchase::class);
        if ($supplierPayment->status === 'cancelled') {
            return response()->json(['success' => true]);
        }

        DB::transaction(function () use ($supplierPayment) {
            $payment = SupplierPayment::lockForUpdate()->findOrFail($supplierPayment->id);
            $allocations = PaymentPurchase::where('supplier_payment_id', $payment->id)->whereNull('deleted_at')->lockForUpdate()->get();
            foreach ($allocations as $allocation) {
                $purchase = Purchase::lockForUpdate()->findOrFail($allocation->purchase_id);
                $newPaid = round(max(0, (float) $purchase->paid_amount - (float) $allocation->montant), 2);
                $purchase->update([
                    'paid_amount' => $newPaid,
                    'payment_statut' => $newPaid <= 0 ? 'unpaid' : ($newPaid >= (float) $purchase->GrandTotal ? 'paid' : 'partial'),
                ]);
                // Use an explicit update so the existing Accounting V2 observer
                // posts the payment-purchase reversal journal.
                $allocation->update(['deleted_at' => now()]);
            }
            if ((float) $payment->opening_balance_amount > 0) {
                $provider = Provider::lockForUpdate()->findOrFail($payment->provider_id);
                $provider->update([
                    'opening_balance' => round((float) $provider->opening_balance + (float) $payment->opening_balance_amount, 2),
                ]);
                $openingAllocation = ProviderPaymentOpeningBalance::where('supplier_payment_id', $payment->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();
                if ($openingAllocation) {
                    $openingAllocation->delete();
                }
            }
            if ($payment->account_id) {
                $account = Account::lockForUpdate()->findOrFail($payment->account_id);
                $account->update(['balance' => round((float) $account->balance + (float) $payment->amount, 2)]);
            }
            $payment->update(['status' => 'cancelled', 'cancelled_at' => now()]);
        }, 5);

        return response()->json(['success' => true]);
    }

    private function outstandingQuery(Request $request, int $providerId)
    {
        $query = Purchase::with(['warehouse:id,name', 'categoryCreditTerms'])
            ->where('provider_id', $providerId)
            ->whereNull('deleted_at')
            ->where('statut', 'received')
            ->whereRaw('GrandTotal > paid_amount')
            ->orderBy('date')->orderBy('id');

        $user = $request->user('api');
        if (! $user->is_all_warehouses) {
            $query->whereIn('warehouse_id', UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id'));
        }
        return $query;
    }
}
