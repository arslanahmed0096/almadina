<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TransferActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'note' => 'nullable|string|max:5000',
            'driver_id' => 'nullable|integer|exists:employees,id',
            'vehicle_details' => 'nullable|string|max:191',
        ];
    }
}
