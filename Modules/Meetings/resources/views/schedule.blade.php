<x-layouts.app title="Schedule a meeting - Meet">
    <div class="mx-auto max-w-xl">
        <h1 class="mb-6 text-2xl font-semibold text-gray-900">Schedule a meeting</h1>

        <form method="POST" action="{{ route('meetings.store') }}" class="space-y-5 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700">Title</label>
                <input type="text" name="title" required value="{{ old('title') }}" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Description</label>
                <textarea name="description" rows="3" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">{{ old('description') }}</textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Start</label>
                    <input type="datetime-local" name="scheduled_start" required class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700">End</label>
                    <input type="datetime-local" name="scheduled_end" required class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                </div>
            </div>

            <input type="hidden" name="timezone" id="timezone-input">

            <div>
                <label class="block text-sm font-medium text-gray-700">Invite by email (comma-separated)</label>
                <input type="text" name="participant_emails" value="{{ old('participant_emails') }}" placeholder="alice@example.com, bob@example.com" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Password (optional)</label>
                <input type="text" name="password" class="mt-1 w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700">Who can join</label>
                <div class="mt-2 space-y-2">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="radio" name="waiting_room_enabled" value="0" class="border-gray-300 text-brand-600" {{ old('waiting_room_enabled', $defaultWaitingRoom) ? '' : 'checked' }}>
                        Anyone with the link joins directly
                    </label>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="radio" name="waiting_room_enabled" value="1" class="border-gray-300 text-brand-600" {{ old('waiting_room_enabled', $defaultWaitingRoom) ? 'checked' : '' }}>
                        Require host approval (waiting room)
                    </label>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" name="guest_enabled" value="1" checked class="rounded border-gray-300 text-brand-600">
                Allow people without an account to join as guests
            </label>

            @if ($errors->any())
                <div class="rounded-lg border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <ul class="list-disc space-y-1 pl-4">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <button type="submit" class="w-full rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">
                Schedule meeting
            </button>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('timezone-input').value = Intl.DateTimeFormat().resolvedOptions().timeZone;
        });
    </script>
</x-layouts.app>
