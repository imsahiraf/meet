<x-layouts.app :title="$list->name . ' - Broadcast list - Meet'">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">{{ $list->name }}</h1>
        <a href="{{ route('broadcast-lists.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back to broadcast lists</a>
    </div>

    <div class="mb-6 rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold text-gray-900">Send a message</h2>
        <form method="POST" action="{{ route('broadcast-lists.send', $list) }}" class="space-y-3">
            @csrf
            <textarea name="body" rows="3" required maxlength="4096" placeholder="Type a message to send to everyone on this list&hellip;"
                      class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">{{ old('body') }}</textarea>
            <div class="flex justify-end">
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-700">Send to {{ $list->recipients->count() }} {{ $list->recipients->count() === 1 ? 'recipient' : 'recipients' }}</button>
            </div>
        </form>
    </div>

    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-5 py-4">
            <h2 class="text-sm font-semibold text-gray-900">Recipients</h2>
        </div>
        <ul class="divide-y divide-gray-100">
            @forelse ($list->recipients as $recipient)
                <li class="flex items-center gap-3 px-5 py-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                        {{ strtoupper(substr($recipient->user->full_name, 0, 1)) }}
                    </span>
                    <span class="text-sm text-gray-900">{{ $recipient->user->full_name }}</span>
                </li>
            @empty
                <li class="px-5 py-8 text-center text-sm text-gray-400">No recipients on this list.</li>
            @endforelse
        </ul>
    </div>

    <form id="broadcast-list-delete-form" method="POST" action="{{ route('broadcast-lists.destroy', $list) }}" class="mt-6">
        @csrf
        @method('DELETE')
        <button type="submit" class="rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50">Delete broadcast list</button>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('broadcast-list-delete-form');
            if (!form) return;
            form.addEventListener('submit', async function (event) {
                event.preventDefault();
                var confirmed = await MeetDialog.confirm('Delete this broadcast list? This does not delete any messages already sent.', { danger: true });
                if (confirmed) {
                    form.submit();
                }
            });
        });
    </script>
</x-layouts.app>
