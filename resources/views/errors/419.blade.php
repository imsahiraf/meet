<x-layouts.guest title="Session expired - Meet">
    <div class="text-center">
        <p class="text-sm font-semibold text-brand-600">419</p>
        <h1 class="mt-2 text-xl font-semibold text-gray-900">Session expired</h1>
        <p class="mt-2 text-sm text-gray-500">Your session expired. Please refresh and try again.</p>
        <a href="{{ url()->previous() }}" class="mt-6 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
            Go back
        </a>
    </div>
</x-layouts.guest>
