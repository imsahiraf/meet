<!doctype html>
<html lang="en" class="h-full">
<head>
        <meta name="robots" content="noindex, nofollow">
<meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Join {{ $meeting->title }} - Meet</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full min-h-screen items-center justify-center bg-gray-50">
    <div class="w-full max-w-sm rounded-2xl border border-gray-100 bg-white p-6 text-center shadow-sm">
        <h1 class="text-lg font-semibold text-gray-900">{{ $meeting->title }}</h1>
        <p class="mt-1 text-sm text-gray-500">Hosted by {{ $meeting->hostDisplayName() }}</p>

        @if (! $meeting->guest_enabled)
            <p class="mt-6 text-sm text-red-600">This meeting does not allow guest access. Please sign in to join.</p>
            <a href="{{ route('login') }}" class="mt-4 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Sign in</a>
        @else
            <div class="mt-6 space-y-3">
                <input type="text" id="guest-name-input" placeholder="Your name" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                @if ($meeting->hasPassword())
                    <input type="password" id="guest-password-input" placeholder="Meeting password" class="w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                @endif
                <button type="button" id="guest-join-btn" class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Join as guest</button>
                <p id="guest-error" class="hidden text-xs text-red-600"></p>
            </div>
            <p class="mt-4 text-xs text-gray-400">
                Or <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700">sign in</a> to join with your account.
            </p>
        @endif
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var btn = document.getElementById('guest-join-btn');
            if (!btn) return;

            btn.addEventListener('click', function () {
                var name = document.getElementById('guest-name-input').value.trim();
                var errorEl = document.getElementById('guest-error');
                if (!name) {
                    errorEl.textContent = 'Enter your name to continue.';
                    errorEl.classList.remove('hidden');
                    return;
                }

                var passwordEl = document.getElementById('guest-password-input');

                axios.post('/api/v1/meetings/{{ $meeting->id }}/guest', {
                    guest_name: name,
                    password: passwordEl ? passwordEl.value : null,
                }).then(function (response) {
                    window.location.href = '/meetings/guest/' + response.data.data.guest_session.session_token;
                }).catch(function (error) {
                    errorEl.textContent = (error.response && error.response.data && error.response.data.message) || 'Could not join this meeting.';
                    errorEl.classList.remove('hidden');
                });
            });
        });
    </script>
</body>
</html>
