<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProcessStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'response_note' => 'required|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*.detail_id' => 'required|integer',
            'items.*.approved_quantity' => 'required|numeric|min:0',
            'items.*.response_reason' => 'nullable|string|max:2000',
        ];
    }
}
