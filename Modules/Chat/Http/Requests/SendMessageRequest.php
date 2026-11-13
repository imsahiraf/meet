<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Base64-encoded AES-GCM ciphertext of a plaintext capped at
        // max_message_length runs a bit longer than the plaintext itself
        // (ciphertext + 16-byte auth tag, then base64's ~1.34x expansion).
        $maxBodyLength = $this->input('encryption_mode') === 'e2e'
            ? (int) ceil((config('chat.max_message_length') + 16) / 3) * 4 + 4
            : config('chat.max_message_length');

        return [
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
            'message_type' => ['sometimes', 'in:text,image,video,audio,file,location,contact,one_view'],
            'body' => ['required_if:message_type,text', 'nullable', 'string', 'max:' . $maxBodyLength],
            'media_id' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'encryption_mode' => ['sometimes', 'in:none,e2e'],
            'iv' => ['required_if:encryption_mode,e2e', 'nullable', 'string', 'max:64'],
            'key_version' => ['required_if:encryption_mode,e2e', 'nullable', 'integer', 'min:1'],
        ];
    }
}
