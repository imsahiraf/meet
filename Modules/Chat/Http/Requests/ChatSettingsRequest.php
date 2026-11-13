<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * RAS-2 API36 - mute, wallpaper, archive, notifications.
     */
    public function rules(): array
    {
        return [
            'is_archived' => ['sometimes', 'boolean'],
            'mute_duration' => ['sometimes', 'nullable', 'in:8_hours,1_week,always,none'],
            'wallpaper_id' => ['sometimes', 'nullable', 'integer', 'exists:chat_wallpapers,id'],
            'notifications_enabled' => ['sometimes', 'boolean'],
        ];
    }
}
