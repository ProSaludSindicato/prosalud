<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreChatbotConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_question' => ['required', 'string'],
            'bot_answer' => ['required', 'string'],
            'client_turn_id' => ['nullable', 'string', 'max:255', 'unique:chatbot_conversations,client_turn_id'],
            'conversation_id' => ['nullable', 'string', 'max:255'],
            'feedback' => ['nullable', 'in:like,dislike'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
