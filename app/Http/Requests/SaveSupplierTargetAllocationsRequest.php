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
            'allocations' => ['required_without:line_allocations', 'array'],
            'allocations.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'allocations.*.allocated_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999999'],
            'line_allocations' => ['required_without:allocations', 'array'],
            'line_allocations.*.supplier_target_line_id' => ['required', 'integer', 'exists:supplier_target_lines,id'],
            'line_allocations.*.warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'line_allocations.*.allocated_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $ids = collect($this->input('allocations', []))->pluck('warehouse_id')->filter();
            if ($ids->unique()->count() !== $ids->count()) {
                $validator->errors()->add('allocations', 'A warehouse may only appear once.');
            }
            $lineKeys = collect($this->input('line_allocations', []))
                ->map(fn ($row) => ($row['supplier_target_line_id'] ?? '').':'.($row['warehouse_id'] ?? ''));
            if ($lineKeys->unique()->count() !== $lineKeys->count()) {
                $validator->errors()->add('line_allocations', 'A target line may only be allocated once to each warehouse.');
            }
        }];
    }
}
