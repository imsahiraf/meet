<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForwardMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBodyLength = (int) ceil((config('chat.max_message_length') + 16) / 3) * 4 + 4;

        return [
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
            // A forwarded E2E message can't just have its ciphertext
            // copied server-side - the destination conversation has its
            // own key, so the client must decrypt the original locally
            // and re-encrypt (or send plaintext, if the destination
            // isn't E2E) itself. Only present when the original message
            // is encrypted; MessageService::forward() requires it in
            // that case and ignores it otherwise.
            'body' => ['sometimes', 'nullable', 'string', 'max:' . $maxBodyLength],
            'encryption_mode' => ['sometimes', 'in:none,e2e'],
            'iv' => ['required_if:encryption_mode,e2e', 'nullable', 'string', 'max:64'],
            'key_version' => ['required_if:encryption_mode,e2e', 'nullable', 'integer', 'min:1'],
        ];
    }
}
