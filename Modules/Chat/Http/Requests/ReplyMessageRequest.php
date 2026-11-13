<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReplyMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBodyLength = $this->input('encryption_mode') === 'e2e'
            ? (int) ceil((config('chat.max_message_length') + 16) / 3) * 4 + 4
            : config('chat.max_message_length');

        return [
            'message_type' => ['sometimes', 'in:text,image,video,audio,file,location,contact,one_view'],
            'body' => ['required_if:message_type,text', 'nullable', 'string', 'max:' . $maxBodyLength],
            'media_id' => ['sometimes', 'nullable', 'integer'],
            'encryption_mode' => ['sometimes', 'in:none,e2e'],
            'iv' => ['required_if:encryption_mode,e2e', 'nullable', 'string', 'max:64'],
            'key_version' => ['required_if:encryption_mode,e2e', 'nullable', 'integer', 'min:1'],
            // The reply's own preview of the quoted original, truncated
            // and re-encrypted client-side under this same conversation
            // key/IV pair as the reply body itself would reuse the IV -
            // it gets its own.
            'reply_preview' => ['required_if:encryption_mode,e2e', 'nullable', 'string', 'max:255'],
            'reply_preview_iv' => ['required_if:encryption_mode,e2e', 'nullable', 'string', 'max:64'],
        ];
    }
}
