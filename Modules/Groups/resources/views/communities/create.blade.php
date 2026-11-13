<x-layouts.app title="New community - Meet">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Create a community</h1>
        <a href="{{ route('communities.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 transition hover:bg-brand-50">
            &larr; Back to communities
        </a>
    </div>

    <form method="POST" action="{{ route('communities.store') }}" class="space-y-6 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        @csrf

        <div>
            <label for="name" class="mb-1 block text-sm font-medium text-gray-700">Community name</label>
            <input id="name" name="name" type="text" required maxlength="150" value="{{ old('name') }}" placeholder="e.g. Neighborhood Watch"
                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <div>
            <label for="description" class="mb-1 block text-sm font-medium text-gray-700">Description <span class="text-gray-400">(optional)</span></label>
            <textarea id="description" name="description" rows="3" maxlength="2000" placeholder="What's this community about?"
                      class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">{{ old('description') }}</textarea>
        </div>

        <p class="text-xs text-gray-400">An "Announcements" group is created automatically for community-wide updates. You can add your other groups to this community afterward.</p>

        <div class="flex justify-end gap-3 border-t border-gray-100 pt-4">
            <a href="{{ route('communities.index') }}" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</a>
            <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">
                Create community
            </button>
        </div>
    </form>
</x-layouts.app>
