<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'category_id' => 'required|uuid|exists:inventory_categories,id',
            'subcategory_id' => 'nullable|uuid|exists:inventory_subcategories,id',
            'description' => 'nullable|string',
            'gender' => 'nullable|in:hombre,mujer,mixto',
            'variant_mode' => 'required|in:simple,size,color,size_color',
            'variants' => 'required|array|min:1',
            'variants.*.size' => 'nullable|string|max:50',
            'variants.*.color_id' => 'nullable|string|exists:inventory_colors,id',
            'variants.*.stock' => 'required|integer|min:0',
            'variants.*.min_stock' => 'required|integer|min:0',
            'variants.*.max_stock' => 'nullable|integer|min:0',
            'variants.*.sku' => 'required|string|max:255|unique:inventory_variants,sku',
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
            'variants.required' => 'Debe agregar al menos una variante',
            'variants.min' => 'Debe agregar al menos una variante',
            'variants.*.stock.required' => 'El stock es obligatorio',
            'variants.*.stock.integer' => 'El stock debe ser un número entero',
            'variants.*.stock.min' => 'El stock no puede ser negativo',
            'variants.*.min_stock.required' => 'El stock mínimo es obligatorio',
            'variants.*.sku.required' => 'El SKU es obligatorio',
            'variants.*.sku.unique' => 'El SKU ya está en uso',
            'variants.*.color_id.exists' => 'El color seleccionado no existe',
        ];
    }
}
