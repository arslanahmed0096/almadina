<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => 'nullable|string|max:5000',
            'return_note' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*.detail_id' => 'required|integer',
            'items.*.accepted_quantity' => 'required|numeric|min:0',
            'items.*.rejected_quantity' => 'required|numeric|min:0',
            'items.*.rejection_reason_code' => 'nullable|string|in:damaged_transport,faulty_product,wrong_product,wrong_model,quantity_mismatch,broken_packaging,missing_accessories,serial_mismatch,not_requested,other',
            'items.*.rejection_note' => 'nullable|string|max:2000',
        ];
    }
}
