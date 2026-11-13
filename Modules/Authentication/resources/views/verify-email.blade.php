<x-layouts.guest title="Verify your email - Meet">
    <h1 class="text-xl font-semibold text-gray-900">Verify your email</h1>
    <p class="mt-1 text-sm text-gray-500">We sent a 6-digit code to <strong>{{ $email }}</strong>.</p>

    <form method="POST" action="{{ route('verify-email.attempt') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="email" value="{{ $email }}">

        <div>
            <label for="otp" class="block text-sm font-medium text-gray-700">Verification code</label>
            <input id="otp" name="otp" type="text" inputmode="numeric" maxlength="6" autofocus required
                   class="mt-1 block w-full rounded-lg border-gray-300 text-center text-lg tracking-[0.5em] shadow-sm focus:border-brand-500 focus:ring-brand-500">
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-brand-600/30 transition hover:bg-brand-700">
            Verify email
        </button>
    </form>

    <form method="POST" action="{{ route('resend-otp') }}" class="mt-4 text-center">
        @csrf
        <input type="hidden" name="email" value="{{ $email }}">
        <button type="submit" class="text-sm font-medium text-brand-600 hover:text-brand-700">
            Didn't get a code? Resend it
        </button>
    </form>
</x-layouts.guest>
