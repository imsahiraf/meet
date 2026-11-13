<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrivacyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'last_seen_visibility' => ['sometimes', 'in:everyone,contacts,nobody'],
            'fake_last_seen_at' => ['sometimes', 'nullable', 'date'],
            'fake_presence_mode' => ['sometimes', 'in:real,always_online,custom_offline'],
            'profile_photo_visibility' => ['sometimes', 'in:everyone,contacts,nobody'],
            'about_visibility' => ['sometimes', 'in:everyone,contacts,nobody'],
            'read_receipts_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
