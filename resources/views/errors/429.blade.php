<x-layouts.guest title="Too many requests - Meet">
    <div class="text-center">
        <p class="text-sm font-semibold text-brand-600">429</p>
        <h1 class="mt-2 text-xl font-semibold text-gray-900">Too many requests</h1>
        <p class="mt-2 text-sm text-gray-500">You've made too many requests. Please slow down and try again shortly.</p>
        <a href="{{ url('/') }}" class="mt-6 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
            Back to Meet
        </a>
    </div>
</x-layouts.guest>
