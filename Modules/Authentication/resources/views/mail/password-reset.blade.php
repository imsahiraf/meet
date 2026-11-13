<x-mail::message>
# Reset your password

Hi {{ $fullName }},

We received a request to reset your Meet account password. Use the token below in the Meet app to set a new password.

<x-mail::panel>
{{ $resetToken }}
</x-mail::panel>

This token expires in {{ $expiresInMinutes }} minutes. If you did not request a password reset, no action is needed.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
