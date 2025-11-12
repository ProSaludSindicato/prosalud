<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255',
            'category_id' => 'sometimes|required|uuid|exists:inventory_categories,id',
            'subcategory_id' => 'nullable|uuid|exists:inventory_subcategories,id',
            'description' => 'nullable|string',
            'gender' => 'sometimes|nullable|in:hombre,mujer,mixto',
            'variant_mode' => 'sometimes|required|in:simple,size,color,size_color',
            'variants' => 'sometimes|array',
            'variants.*.id' => 'nullable|uuid|exists:inventory_variants,id',
            'variants.*.size' => 'nullable|string|max:50',
            'variants.*.color_id' => 'nullable|string|exists:inventory_colors,id',
            'variants.*.stock' => 'required|integer|min:0',
            'variants.*.min_stock' => 'required|integer|min:0',
            'variants.*.max_stock' => 'nullable|integer|min:0',
            'variants.*.sku' => [
                'required',
                'string',
                'max:255',
                function ($attribute, $value, $fail) {
                    // Extract variant index from attribute path (e.g., "variants.0.sku" -> 0)
                    preg_match('/variants\.(\d+)\.sku/', $attribute, $matches);
                    $index = $matches[1] ?? null;

                    if ($index === null) {
                        return;
                    }

                    // Get the variant ID if it exists
                    $variantId = $this->input("variants.{$index}.id");

                    // Check if SKU is unique, excluding this variant
                    $query = \App\Models\InventoryVariant::where('sku', $value);

                    if ($variantId) {
                        $query->where('id', '!=', $variantId);
                    }

                    if ($query->exists()) {
                        $fail('El SKU ya está en uso.');
                    }
                },
            ],
            'variants.*.deleted' => 'nullable|boolean',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del producto es obligatorio',
            'category_id.required' => 'La categoría es obligatoria',
            'category_id.exists' => 'La categoría seleccionada no existe',
            'subcategory_id.exists' => 'La subcategoría seleccionada no existe',
            'gender.in' => 'El género seleccionado no es válido',
            'variant_mode.required' => 'El modo de variante es obligatorio',
            'variant_mode.in' => 'El modo de variante no es válido',
            'variants.*.stock.required' => 'El stock es obligatorio',
            'variants.*.stock.integer' => 'El stock debe ser un número entero',
            'variants.*.stock.min' => 'El stock no puede ser negativo',
            'variants.*.min_stock.required' => 'El stock mínimo es obligatorio',
            'variants.*.sku.required' => 'El SKU es obligatorio',
            'variants.*.color_id.exists' => 'El color seleccionado no existe',
        ];
    }
}
