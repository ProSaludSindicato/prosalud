<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreHospitalRequestRequest extends FormRequest
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
            'hospital_id' => 'required|string|max:255',
            'hospital_name' => 'required|string|max:255',
            'requested_by' => 'nullable|string|max:255',
            'observations' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:inventory_products,id',
            'items.*.variant_id' => 'nullable|uuid|exists:inventory_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.notes' => 'nullable|string',
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
            'hospital_id.required' => 'El ID del hospital es obligatorio',
            'hospital_name.required' => 'El nombre del hospital es obligatorio',
            'items.required' => 'Debe agregar al menos un ítem a la solicitud',
            'items.min' => 'Debe agregar al menos un ítem a la solicitud',
            'items.*.product_id.required' => 'El producto es obligatorio',
            'items.*.product_id.exists' => 'El producto seleccionado no existe',
            'items.*.variant_id.exists' => 'La variante seleccionada no existe',
            'items.*.quantity.required' => 'La cantidad es obligatoria',
            'items.*.quantity.min' => 'La cantidad debe ser al menos 1',
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate stock availability for each item
            $items = $this->input('items', []);

            foreach ($items as $index => $item) {
                if (!isset($item['variant_id']) || !isset($item['quantity'])) {
                    continue;
                }

                $variant = \App\Models\InventoryVariant::find($item['variant_id']);

                if ($variant && $variant->stock < $item['quantity']) {
                    $validator->errors()->add(
                        "items.{$index}.quantity",
                        "Stock insuficiente. Disponible: {$variant->stock}, solicitado: {$item['quantity']}"
                    );
                }
            }
        });
    }
}
