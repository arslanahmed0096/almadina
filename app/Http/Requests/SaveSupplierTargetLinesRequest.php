<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSupplierTargetLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.type' => ['required', 'in:product,category'],
            'lines.*.targetable_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'lines.*.target_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $lines = collect($this->input('lines', []));
            $keys = $lines->map(fn ($line) => ($line['type'] ?? '').':'.($line['targetable_id'] ?? ''));
            if ($keys->unique()->count() !== $keys->count()) {
                $validator->errors()->add('lines', 'Duplicate product or category target lines are not allowed.');
            }
            $products = $lines->where('type', 'product')->pluck('targetable_id')->map(fn ($id) => (int) $id)->unique();
            $categories = $lines->where('type', 'category')->pluck('targetable_id')->map(fn ($id) => (int) $id)->unique();
            if (Product::whereIn('id', $products)->whereNull('deleted_at')->count() !== $products->count()) {
                $validator->errors()->add('lines', 'One or more selected products are invalid.');
            }
            if (Category::whereIn('id', $categories)->whereNull('deleted_at')->count() !== $categories->count()) {
                $validator->errors()->add('lines', 'One or more selected categories are invalid.');
            }
            if ($categories->isNotEmpty()) {
                $categoryProducts = Product::whereNull('deleted_at')->where(function ($query) use ($categories) {
                    $query->whereIn('category_id', $categories)
                        ->orWhereHas('categories', fn ($q) => $q->whereIn('categories.id', $categories));
                })->pluck('id');
                if ($products->intersect($categoryProducts)->isNotEmpty()) {
                    $validator->errors()->add('lines', 'A product cannot be targeted directly and through a selected category.');
                }
            }
        }];
    }
}
