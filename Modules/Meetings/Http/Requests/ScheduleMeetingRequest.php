<?php

namespace Modules\Meetings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'scheduled_start' => ['required', 'date', 'after:now'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
            // 'all_with_bc' also accepts legacy tz aliases (e.g. "Asia/Calcutta")
            // that some browsers/OSes still report via Intl.DateTimeFormat().
            'timezone' => ['required', 'timezone:all_with_bc'],
            'password' => ['sometimes', 'nullable', 'string', 'max:64'],
            'guest_enabled' => ['sometimes', 'boolean'],
            'waiting_room_enabled' => ['sometimes', 'boolean'],
            'participant_ids' => ['sometimes', 'array'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
            'participant_emails' => ['sometimes', 'array'],
            'participant_emails.*' => ['email', 'max:255'],
        ];
    }
}
