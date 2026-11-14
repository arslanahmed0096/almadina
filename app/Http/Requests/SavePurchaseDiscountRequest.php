<?php

namespace App\Http\Requests;

use App\Models\PurchaseDiscount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePurchaseDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $discount = $this->route('purchase_discount');
        $discountId = $discount instanceof PurchaseDiscount ? $discount->id : $discount;

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('purchase_discounts', 'name')
                    ->ignore($discountId)
                    ->where(fn ($query) => $query
                        ->where('category', $this->input('category'))
                        ->whereNull('deleted_at')),
            ],
            'category' => ['required', Rule::in(PurchaseDiscount::CATEGORIES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(PurchaseDiscount::STATUSES)],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'A discount with this name already exists in the selected category.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = preg_replace('/\s+/', ' ', trim((string) $this->input('name')));
        $description = trim((string) $this->input('description'));
        $this->merge([
            'name' => $name,
            'description' => $description === '' ? null : $description,
            'status' => $this->input('status', 'active'),
        ]);
    }
}
