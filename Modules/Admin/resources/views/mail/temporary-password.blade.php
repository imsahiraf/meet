<x-mail::message>
# Your password was reset

Hi {{ $fullName }},

An administrator reset your Meet account password. Use the temporary password below to log in - you'll be asked to set a new one right away.

<x-mail::panel>
{{ $temporaryPassword }}
</x-mail::panel>

If you weren't expecting this, contact your administrator.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
