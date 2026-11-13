<x-layouts.guest title="Set a new password - Meet">
    <h1 class="text-xl font-semibold text-gray-900">Set a new password</h1>
    <p class="mt-1 text-sm text-gray-500">An administrator reset your password. Choose a new one to continue.</p>

    <form method="POST" action="{{ route('force-password-change.update') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="new_password" class="block text-sm font-medium text-gray-700">New password</label>
            <input id="new_password" name="new_password" type="password" required autofocus
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500">
            <p class="mt-1 text-xs text-gray-400">At least 8 characters, with upper/lowercase, a number, and a symbol.</p>
        </div>

        <div>
            <label for="new_password_confirmation" class="block text-sm font-medium text-gray-700">Confirm new password</label>
            <input id="new_password_confirmation" name="new_password_confirmation" type="password" required
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/30 transition hover:bg-brand-700">
            Set password
        </button>
    </form>
</x-layouts.guest>
