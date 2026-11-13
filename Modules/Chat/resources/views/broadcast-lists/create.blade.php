<x-layouts.app title="New broadcast list - Meet">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">New broadcast list</h1>
        <a href="{{ route('broadcast-lists.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 transition hover:bg-brand-50">
            &larr; Back to broadcast lists
        </a>
    </div>

    <form method="POST" action="{{ route('broadcast-lists.store') }}" class="space-y-6 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        @csrf

        <div>
            <label for="name" class="mb-1 block text-sm font-medium text-gray-700">List name</label>
            <input id="name" name="name" type="text" required maxlength="150" value="{{ old('name') }}" placeholder="e.g. Close Friends"
                   class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
        </div>

        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Recipients</label>

            @if ($contacts->isEmpty())
                <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500">
                    You don't have any contacts yet. <a href="{{ route('contacts.index') }}" class="font-medium text-brand-600 hover:text-brand-700">Add some contacts</a> before creating a broadcast list.
                </p>
            @else
                <div class="max-h-72 overflow-y-auto rounded-lg border border-gray-200 divide-y divide-gray-100">
                    @foreach ($contacts as $contact)
                        <label class="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-gray-50">
                            <input type="checkbox" name="recipients[]" value="{{ $contact->contact_user_id }}"
                                   class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                   @checked(collect(old('recipients'))->contains($contact->contact_user_id))>
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                                {{ strtoupper(substr($contact->contactUser->full_name, 0, 1)) }}
                            </span>
                            <span class="text-sm text-gray-900">{{ $contact->contactUser->full_name }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex justify-end gap-3 border-t border-gray-100 pt-4">
            <a href="{{ route('broadcast-lists.index') }}" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</a>
            <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">
                Create broadcast list
            </button>
        </div>
    </form>
</x-layouts.app>
