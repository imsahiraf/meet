<?php

namespace Modules\Contacts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendContactRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receiver_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
