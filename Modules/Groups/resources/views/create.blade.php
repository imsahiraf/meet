<x-layouts.app title="New group - Meet">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Create a group</h1>
        <a href="{{ route('groups.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 transition hover:bg-brand-50">
            &larr; Back to groups
        </a>
    </div>

    <form method="POST" action="{{ route('groups.store') }}" class="space-y-6 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        @csrf

        <div>
            <label for="group_name" class="mb-1 block text-sm font-medium text-gray-700">Group name</label>
            <input id="group_name" name="group_name" type="text" required maxlength="150" value="{{ old('group_name') }}" placeholder="e.g. Weekend Trip Planning">
        </div>

        <div>
            <label for="description" class="mb-1 block text-sm font-medium text-gray-700">Description <span class="text-gray-400">(optional)</span></label>
            <textarea id="description" name="description" rows="3" maxlength="2000" placeholder="What's this group about?">{{ old('description') }}</textarea>
        </div>

        <div>
            <label class="mb-2 block text-sm font-medium text-gray-700">Add members</label>

            @if ($contacts->isEmpty())
                <p class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-500">
                    You don't have any contacts yet. <a href="{{ route('contacts.index') }}" class="font-medium text-brand-600 hover:text-brand-700">Add some contacts</a> before creating a group.
                </p>
            @else
                <div class="max-h-72 overflow-y-auto rounded-lg border border-gray-200 divide-y divide-gray-100">
                    @foreach ($contacts as $contact)
                        <label class="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-gray-50">
                            <input type="checkbox" name="members[]" value="{{ $contact->contact_user_id }}"
                                   class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                   @checked(collect(old('members'))->contains($contact->contact_user_id))>
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
            <a href="{{ route('groups.index') }}" class="rounded-lg px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100">Cancel</a>
            <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">
                Create group
            </button>
        </div>
    </form>
</x-layouts.app>
