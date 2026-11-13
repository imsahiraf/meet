<?php

namespace Modules\Calls\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receiver_id' => ['required', 'integer', 'exists:users,id'],
            'conversation_id' => ['sometimes', 'nullable', 'integer', 'exists:conversations,id'],
        ];
    }
}
