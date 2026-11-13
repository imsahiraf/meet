<x-layouts.guest title="Add another account - Meet">
    <h1 class="text-xl font-semibold text-gray-900">Add another account</h1>
    <p class="mt-1 text-sm text-gray-500">Your other account stays signed in - switch back to it any time from Settings.</p>

    <form method="POST" action="{{ route('accounts.add') }}" class="mt-6 space-y-4">
        @csrf
        {{-- Always remembered, unlike the normal login form's checkbox -
             the whole point of adding an account here is to be able to
             switch back to it later without a password. --}}
        <input type="hidden" name="remember" value="1">

        <div>
            <label for="login" class="block text-sm font-medium text-gray-700">Email or phone number</label>
            <input id="login" name="login" type="text" value="{{ old('login') }}" required autofocus
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <input id="password" name="password" type="password" required
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/30 transition hover:bg-brand-700">
            Add account
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-500">
        <a href="{{ route('profile.switch-accounts') }}" class="font-medium text-brand-600 hover:text-brand-700">&larr; Back to switch account</a>
    </p>
</x-layouts.guest>
