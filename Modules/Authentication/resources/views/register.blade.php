<x-layouts.guest title="Sign up - Meet">
    <h1 class="text-xl font-semibold text-gray-900">Create your account</h1>
    <p class="mt-1 text-sm text-gray-500">Join Meet - chat, call, and meet in one place.</p>

    <form method="POST" action="{{ route('register.attempt') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="full_name" class="block text-sm font-medium text-gray-700">Full name</label>
            <input id="full_name" name="full_name" type="text" value="{{ old('full_name') }}" required autofocus
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-gray-700">Email address</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <div>
            <label for="phone" class="block text-sm font-medium text-gray-700">Phone number</label>
            <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" required placeholder="+91XXXXXXXXXX"
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
            <input id="password" name="password" type="password" required
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            <p class="mt-1 text-xs text-gray-400">At least 8 characters, with upper/lowercase, a number, and a symbol.</p>
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-gray-700">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required
                   class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/30 transition hover:bg-brand-700">
            Create account
        </button>
    </form>

    <x-auth.insights-button label="Quick sign up with Meet Insights" />

    <p class="mt-6 text-center text-sm text-gray-500">
        Already have an account?
        <a href="{{ route('login') }}" class="font-medium text-brand-600 hover:text-brand-700">Log in</a>
    </p>
</x-layouts.guest>
