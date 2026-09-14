<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSupplierTargetDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'exists:providers,id'],
            'target_name' => ['required', 'string', 'max:191'],
            'period_type' => ['required', 'in:annual,monthly'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'measurement_type' => ['nullable', 'in:quantity'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $this->start_date || ! $this->end_date) {
                return;
            }
            $start = Carbon::parse($this->start_date);
            $end = Carbon::parse($this->end_date);
            if ($this->period_type === 'monthly' && ! $start->isSameMonth($end)) {
                $validator->errors()->add('end_date', 'A monthly target must start and end in the same calendar month.');
            }
            if ($this->period_type === 'annual' && $start->year !== $end->year) {
                $validator->errors()->add('end_date', 'An annual target must start and end in the same calendar year.');
            }
        }];
    }
}
