<x-mail::message>
# Meeting invitation: {{ $meeting->title }}

{{ $organizerName }} has invited you to a meeting on {{ config('app.name') }}.

@if ($meeting->description)
{{ $meeting->description }}
@endif

- **When:** {{ $meeting->scheduled_start->format('l, F j, Y g:i A') }} ({{ $meeting->timezone }})
- **Meeting ID:** {{ $meeting->meeting_code }}
@if ($meeting->hasPassword())
- **Password required to join**
@endif

<x-mail::button :url="$joinUrl">
Join meeting
</x-mail::button>

A calendar invite (.ics) is attached to this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
