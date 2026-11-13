<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EditMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBodyLength = (int) ceil((config('chat.max_message_length') + 16) / 3) * 4 + 4;

        return [
            'body' => ['required', 'string', 'max:' . $maxBodyLength],
            // Present only when re-encrypting an already-E2E message (a
            // fresh IV every time - the same (key, IV) pair must never
            // be reused). The message keeps its original key_version;
            // only rotation (membership change) issues a new one.
            'iv' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
