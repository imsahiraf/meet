<?php

namespace Modules\Meetings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            // 'meeting' is deliberately excluded - those are only ever
            // created via CalendarService::scheduleMeeting(), which
            // also handles invitations/RSVPs/reminders this endpoint
            // has no way to set up.
            'event_type' => ['required', 'string', 'in:reminder,personal,holiday,task'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            // 'all_with_bc' (not the plain default group) matches
            // Modules/User/Http/Requests/UpdatePreferencesRequest.php's
            // exact convention - some real browsers/OSes resolve
            // Intl.DateTimeFormat().resolvedOptions().timeZone to a
            // legacy/backward-compatible IANA alias (e.g. "Asia/
            // Calcutta" instead of "Asia/Kolkata"), which the stricter
            // default group rejects outright.
            'timezone' => ['required', 'string', 'timezone:all_with_bc'],
        ];
    }
}
