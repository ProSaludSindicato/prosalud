<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSstReturnRequest extends FormRequest
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
            'affiliateId' => 'required|string|max:100',
            'affiliateDocumentType' => 'required|string|max:5',
            'affiliateDocumentNumber' => 'required|string|max:50',
            'receivedBy' => 'required|string|max:255',
            'receivedByName' => 'required|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.itemId' => 'required|string|max:150',
            'items.*.quantity' => 'required|integer|min:1|max:100',
            'items.*.variant' => 'nullable|array',
            'items.*.variant.color' => 'nullable|string|max:100',
            'items.*.variant.size' => 'nullable|string|max:100',
            'signatureData' => 'required|string',
            'signedDocumentType' => 'required|string|max:5',
            'signedDocumentNumber' => 'required|string|max:50',
            'reason' => 'nullable|string|in:retirement,replacement,other',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'affiliateDocumentType' => strtoupper((string) $this->input('affiliateDocumentType')),
            'signedDocumentType' => strtoupper((string) $this->input('signedDocumentType')),
            'reason' => $this->input('reason', 'replacement'),
        ]);
    }
}
