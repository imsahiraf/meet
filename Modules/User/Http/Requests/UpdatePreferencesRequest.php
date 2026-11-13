<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'language' => ['sometimes', 'string', 'max:10'],
            'timezone' => ['sometimes', 'string', 'max:100', 'timezone:all_with_bc'],
            'theme' => ['sometimes', 'in:light,dark,system'],
            'accent' => ['sometimes', 'nullable', 'in:' . implode(',', array_keys(\Modules\User\Models\UserPreference::ACCENTS))],
            'notification_sound' => ['sometimes', 'boolean'],
            'auto_download_photos' => ['sometimes', 'boolean'],
            'auto_download_videos' => ['sometimes', 'boolean'],
            'auto_download_documents' => ['sometimes', 'boolean'],
            'notify_messages' => ['sometimes', 'boolean'],
            'notify_calls' => ['sometimes', 'boolean'],
            'notify_meetings' => ['sometimes', 'boolean'],
            'notify_contact_requests' => ['sometimes', 'boolean'],
            'default_wallpaper_id' => ['sometimes', 'nullable', 'integer', 'exists:chat_wallpapers,id'],
            'font_size' => ['sometimes', 'in:small,medium,large'],
        ];
    }
}
