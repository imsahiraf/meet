<x-mail::message>
# Verify your email

Hi {{ $fullName }},

Use the code below to verify your email address and activate your Meet account.

<x-mail::panel>
# {{ $otpCode }}
</x-mail::panel>

This code expires in {{ $expiresInMinutes }} minutes. If you did not request this, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
