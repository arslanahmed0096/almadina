<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSupplierTargetAllocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allocations' => ['present', 'array'],
            'allocations.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'allocations.*.allocated_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $ids = collect($this->input('allocations', []))->pluck('warehouse_id')->filter();
            if ($ids->unique()->count() !== $ids->count()) {
                $validator->errors()->add('allocations', 'A warehouse may only appear once.');
            }
        }];
    }
}
