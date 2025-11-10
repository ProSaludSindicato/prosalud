<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
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
            'name' => 'required|string|max:255|unique:inventory_categories,name',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'subcategories' => 'nullable|array',
            'subcategories.*.name' => 'required|string|max:255',
            'subcategories.*.description' => 'nullable|string',
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
            'name.required' => 'El nombre de la categoría es obligatorio',
            'name.unique' => 'Ya existe una categoría con este nombre',
            'subcategories.*.name.required' => 'El nombre de la subcategoría es obligatorio',
        ];
    }
}
