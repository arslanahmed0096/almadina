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
            'items' => 'required|array|min:1',
            'items.*.detail_id' => 'required|integer',
            'items.*.received_quantity' => 'required|numeric|min:0',
        ];
    }
}
