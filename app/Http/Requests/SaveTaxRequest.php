<?php

namespace App\Http\Requests;

use App\Models\Tax;
use App\Models\UserWarehouse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveTaxRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $taxId = $this->route('tax')?->id ?? $this->route('tax');
        return [
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('taxes', 'code')->ignore($taxId)->where('scope_key', 'global')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:2000'],
            'calculation_type' => ['required', Rule::in(Tax::CALCULATION_TYPES)],
            'rate' => ['required', 'numeric', 'min:0', Rule::when($this->input('calculation_type') === 'percentage', ['max:100'])],
            'behavior' => ['required', Rule::in(Tax::BEHAVIORS)],
            'transaction_types' => ['required', 'array', 'min:1'],
            'transaction_types.*' => ['required', Rule::in(Tax::TRANSACTION_TYPES)],
            'price_type_ids' => ['required', 'array', 'min:1'],
            'price_type_ids.*' => ['integer', 'distinct', 'exists:tax_price_types,id'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'distinct', 'exists:warehouses,id'],
            'effective_start_date' => ['nullable', 'date'],
            'effective_end_date' => ['nullable', 'date', 'after_or_equal:effective_start_date'],
            'priority' => ['required', 'integer', 'min:0', 'max:65535'],
            'is_compound' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $user = $this->user('api');
            if (! $user || $user->is_all_warehouses || empty($this->warehouse_ids)) return;
            $allowed = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id');
            if (collect($this->warehouse_ids)->diff($allowed)->isNotEmpty()) {
                $validator->errors()->add('warehouse_ids', 'One or more branches are outside your access.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
    }
}
