<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Constants\Providers;

class StoreWellnessEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'category' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['required', 'string', 'max:255'],
            'attendees' => ['nullable', 'integer', 'min:0'],
            'gift' => ['nullable', 'string', 'max:255'],
            'provider' => ['nullable', 'string', Rule::in([Providers::PROSALUD])],
            'is_visible' => ['nullable', 'boolean'],
        ];
    }
}
