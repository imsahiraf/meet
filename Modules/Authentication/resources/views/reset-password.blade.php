<x-layouts.guest title="Reset password - Meet">
    <h1 class="text-xl font-semibold text-gray-900">Reset your password</h1>
    <p class="mt-1 text-sm text-gray-500">Paste the reset token from your email and choose a new password.</p>

    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="token" class="block text-sm font-medium text-gray-700">Reset token</label>
            <input id="token" name="token" type="text" value="{{ old('token', $token) }}" required autofocus
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">New password</label>
            <input id="password" name="password" type="password" required
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/30 transition hover:bg-brand-700">
            Reset password
        </button>
    </form>
</x-layouts.guest>
