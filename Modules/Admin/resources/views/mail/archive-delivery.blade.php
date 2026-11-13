<x-mail::message>
# System archive ready

A {{ str_replace('_', ' ', $archive->scope) }} archive was generated on {{ $archive->created_at->format('l, F j, Y g:i A') }}.

- **Size:** {{ number_format($archive->size_bytes / 1048576, 2) }} MB
- **Checksum (SHA-256):** {{ $archive->checksum }}

@if ($attachmentPath)
The password-protected archive is attached to this email.
@else
The archive exceeded the email attachment size limit - contact your administrator for a secure download link.
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
