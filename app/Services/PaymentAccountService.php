<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PaymentMethod;
use Illuminate\Validation\ValidationException;

class PaymentAccountService
{
    public function accountTypeForMethod(PaymentMethod $method): ?string
    {
        $name = strtolower(trim((string) $method->name));

        if (str_contains($name, 'easypaisa') || str_contains($name, 'easy paisa')) {
            return 'easypaisa';
        }

        if (str_contains($name, 'bank')) {
            return 'bank';
        }

        if ($name === 'cash') {
            return null;
        }

        // Stable IDs from the original payment-method migration are fallbacks
        // for installations where the labels have been renamed.
        if ((int) $method->id === 6) {
            return 'bank';
        }

        return null;
    }

    public function resolve(PaymentMethod $method, $accountId, string $field = 'account_id'): ?Account
    {
        $requiredType = $this->accountTypeForMethod($method);
        if ($requiredType === null) {
            return null;
        }

        if (! $accountId) {
            throw ValidationException::withMessages([
                $field => ['Select a '.($requiredType === 'easypaisa' ? 'Easypaisa' : 'bank').' account.'],
            ]);
        }

        $account = Account::whereNull('deleted_at')->find($accountId);
        if (! $account || $account->account_type !== $requiredType) {
            throw ValidationException::withMessages([
                $field => ['The selected account must be an active '.($requiredType === 'easypaisa' ? 'Easypaisa' : 'bank').' account.'],
            ]);
        }

        return $account;
    }
}
