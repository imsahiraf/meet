<!doctype html>
<html lang="en" class="h-full">
<head>
        <meta name="robots" content="noindex, nofollow">
<meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Start a meeting - Meet</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full min-h-screen items-center justify-center bg-gray-50">
    <div class="w-full max-w-sm rounded-2xl border border-gray-100 bg-white p-6 text-center shadow-sm">
        <h1 class="text-lg font-semibold text-gray-900">Start a meeting</h1>
        <p class="mt-1 text-sm text-gray-500">No account needed - get a shareable link instantly.</p>

        @if (! $enabled)
            <p class="mt-6 text-sm text-red-600">Guest meeting creation is currently disabled. Please sign in to start a meeting.</p>
            <a href="{{ route('login') }}" class="mt-4 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Sign in</a>
        @else
            <form method="POST" action="{{ route('meetings.guest-create') }}" class="mt-6 space-y-3 text-left">
                @csrf
                <div>
                    <label class="text-xs font-medium text-gray-600">Your name</label>
                    <input type="text" name="guest_name" value="{{ old('guest_name') }}" required autofocus
                           class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    @error('guest_name')
                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">Meeting title (optional)</label>
                    <input type="text" name="title" value="{{ old('title') }}" placeholder="Guest meeting"
                           class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                </div>
                <div>
                    <label class="text-xs font-medium text-gray-600">Password (optional)</label>
                    <input type="password" name="password"
                           class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                </div>
                <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Start meeting</button>
            </form>
            <p class="mt-4 text-xs text-gray-400">
                Or <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700">sign in</a> for full meeting controls.
            </p>
        @endif
    </div>
</body>
</html>
