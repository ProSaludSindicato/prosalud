<?php

namespace App\Http\Requests;

use App\Models\VotingSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVotingModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'active_mode' => ['required', 'string', Rule::in(VotingSetting::validModes())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'active_mode.required' => 'El modo de votación es obligatorio.',
            'active_mode.in' => 'El modo de votación debe ser: none, candidate o assembly.',
        ];
    }
}
