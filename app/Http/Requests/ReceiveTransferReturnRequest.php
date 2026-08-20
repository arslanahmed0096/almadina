<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveTransferReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'note' => 'nullable|string|max:5000',
            'items' => 'required|array|min:1',
            'items.*.return_detail_id' => 'required|integer',
            'items.*.condition' => 'required|in:saleable,faulty,damaged,repair_required,quarantine',
        ];
    }
}
