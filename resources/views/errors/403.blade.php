<x-layouts.guest title="Access denied - Meet">
    <div class="text-center">
        <p class="text-sm font-semibold text-brand-600">403</p>
        <h1 class="mt-2 text-xl font-semibold text-gray-900">Access denied</h1>
        <p class="mt-2 text-sm text-gray-500">You don't have permission to view this page.</p>
        <a href="{{ url('/') }}" class="mt-6 inline-block rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
            Back to Meet
        </a>
    </div>
</x-layouts.guest>
