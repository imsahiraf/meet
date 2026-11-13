<x-layouts.guest title="Forgot password - Meet">
    <h1 class="text-xl font-semibold text-gray-900">Forgot your password?</h1>
    <p class="mt-1 text-sm text-gray-500">Enter your email and we'll send you a reset token.</p>

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/30 transition hover:bg-brand-700">
            Send reset link
        </button>
    </form>

    <p class="mt-6 text-center text-sm text-gray-500">
        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700">Back to log in</a>
    </p>
</x-layouts.guest>
