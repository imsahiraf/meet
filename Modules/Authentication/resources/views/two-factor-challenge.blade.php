<x-layouts.guest title="Two-factor authentication - Meet">
    <h1 class="text-xl font-semibold text-gray-900">Enter your authenticator code</h1>
    <p class="mt-1 text-sm text-gray-500">Open your authenticator app and enter the 6-digit code, or use one of your recovery codes.</p>

    <form method="POST" action="{{ route('two-factor.challenge.verify') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="challenge_token" value="{{ $challenge }}">

        <div>
            <label for="code" class="block text-sm font-medium text-gray-700">Code</label>
            <input id="code" name="code" type="text" inputmode="numeric" maxlength="9" autofocus required
                   class="mt-1 block w-full rounded-lg border-gray-300 text-center text-lg tracking-[0.3em] shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>

        <label class="flex items-center gap-2 text-sm text-gray-600">
            <input type="checkbox" name="remember_device" value="1" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500" @checked(old('remember_device'))>
            Remember this device for 30 days
        </label>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/30 transition hover:bg-brand-700">
            Verify
        </button>
    </form>

    <p class="mt-4 text-center text-sm text-gray-500">
        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700">Back to login</a>
    </p>
</x-layouts.guest>
