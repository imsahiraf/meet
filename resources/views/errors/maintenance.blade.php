<x-layouts.guest title="Down for maintenance - Meet">
    <div class="text-center">
        <p class="text-sm font-semibold text-brand-600">503</p>
        <h1 class="mt-2 text-xl font-semibold text-gray-900">Down for maintenance</h1>
        <p class="mt-2 text-sm text-gray-500">{{ $message }}</p>
        <a href="{{ route('login') }}" class="mt-6 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
            Administrator sign in
        </a>
    </div>
</x-layouts.guest>
