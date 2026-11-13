<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MigrateMessagesToEncryptedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxBodyLength = (int) ceil((config('chat.max_message_length') + 16) / 3) * 4 + 4;

        return [
            'key_version' => ['required', 'integer', 'min:1'],
            'messages' => ['required', 'array', 'min:1', 'max:500'],
            'messages.*.message_id' => ['required', 'integer'],
            'messages.*.body' => ['required', 'string', 'max:' . $maxBodyLength],
            'messages.*.iv' => ['required', 'string', 'max:64'],
        ];
    }
}
