<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryEntryRequest extends FormRequest
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
            'supplier_id' => 'required|string|max:255',
            'supplier_name' => 'nullable|string|max:255',
            'received_at' => 'required|date',
            'document_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'location_id' => 'nullable|uuid|exists:inventory_locations,id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:inventory_products,id',
            'items.*.variant_id' => 'nullable|uuid|exists:inventory_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
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
            'supplier_id.required' => 'El proveedor es obligatorio.',
            'received_at.required' => 'La fecha de recepción es obligatoria.',
            'received_at.date' => 'La fecha de recepción no tiene un formato válido.',
            'location_id.exists' => 'La bodega seleccionada no existe.',
            'items.required' => 'Debe agregar al menos un producto en la entrada.',
            'items.min' => 'Debe agregar al menos un producto en la entrada.',
            'items.*.product_id.required' => 'El producto es obligatorio.',
            'items.*.product_id.exists' => 'El producto seleccionado no existe.',
            'items.*.variant_id.exists' => 'La variante seleccionada no existe.',
            'items.*.quantity.required' => 'La cantidad es obligatoria.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor que cero.',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);

            foreach ($items as $index => $item) {
                if (empty($item['product_id'])) {
                    continue;
                }

                $product = \App\Models\InventoryProduct::find($item['product_id']);

                if (!$product) {
                    $validator->errors()->add("items.{$index}.product_id", 'El producto seleccionado no existe.');
                    continue;
                }

                if (!empty($item['variant_id'])) {
                    $variant = \App\Models\InventoryVariant::where('id', $item['variant_id'])
                        ->where('product_id', $product->id)
                        ->first();

                    if (!$variant) {
                        $validator->errors()->add("items.{$index}.variant_id", 'La variante seleccionada no pertenece al producto.');
                    }
                } else {
                    $requiresVariant = in_array($product->variant_mode, ['size', 'color', 'size_color'], true);

                    if ($requiresVariant) {
                        $validator->errors()->add("items.{$index}.variant_id", 'Debe seleccionar una variante para este producto.');
                    }
                }
            }
        });
    }
}
