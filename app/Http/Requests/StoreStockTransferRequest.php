<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transfer.from_warehouse' => 'required|integer|exists:warehouses,id|different:transfer.to_warehouse',
            'transfer.to_warehouse' => 'required|integer|exists:warehouses,id',
            'transfer.date' => 'required|date',
            'transfer.required_date' => 'nullable|date|after_or_equal:transfer.date',
            'transfer.notes' => 'required|string|max:5000',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|integer|exists:products,id',
            'details.*.product_variant_id' => 'nullable|integer',
            'details.*.purchase_unit_id' => 'nullable|integer',
            'details.*.quantity' => 'required|numeric|gt:0',
            'details.*.batches' => 'nullable|array',
            'GrandTotal' => 'nullable|numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'transfer.notes.required' => 'Please enter a stock request note.',
            'transfer.from_warehouse.required' => 'Please select the source warehouse.',
            'transfer.to_warehouse.required' => 'Please select the destination warehouse.',
        ];
    }
}
