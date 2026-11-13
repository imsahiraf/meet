<x-layouts.app title="Contact requests - Meet">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900">Contact requests</h1>
        <a href="{{ route('contacts.index') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-600 transition hover:bg-brand-50">
            &larr; Back to contacts
        </a>
    </div>

    <div class="space-y-8">
        <section>
            <h2 class="mb-3 text-sm font-semibold text-gray-900">Incoming</h2>
            <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                <ul class="divide-y divide-gray-100">
                    @forelse ($incoming as $request)
                        <li class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-gray-50/80">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                                    {{ strtoupper(substr($request->sender->full_name, 0, 1)) }}
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium text-gray-900">{{ $request->sender->full_name }}</p>
                                    @if ($request->message)
                                        <p class="truncate text-sm text-gray-500">&ldquo;{{ $request->message }}&rdquo;</p>
                                    @endif
                                </div>
                            </div>
                            <div class="flex shrink-0 gap-2">
                                <form method="POST" action="{{ route('contacts.request.accept', $request) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">Accept</button>
                                </form>
                                <form method="POST" action="{{ route('contacts.request.reject', $request) }}">
                                    @csrf
                                    <button type="submit" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50">Reject</button>
                                </form>
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-10 text-center text-sm text-gray-400">No incoming requests.</li>
                    @endforelse
                </ul>
            </div>
        </section>

        <section>
            <h2 class="mb-3 text-sm font-semibold text-gray-900">Sent</h2>
            <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                <ul class="divide-y divide-gray-100">
                    @forelse ($outgoing as $request)
                        <li class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-gray-50/80">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100 text-sm font-semibold text-gray-500">
                                    {{ strtoupper(substr($request->receiver->full_name, 0, 1)) }}
                                </span>
                                <p class="truncate text-sm font-medium text-gray-900">{{ $request->receiver->full_name }}</p>
                            </div>
                            <form method="POST" action="{{ route('contacts.request.cancel', $request) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="shrink-0 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-500 transition hover:bg-red-50 hover:text-red-600">Cancel</button>
                            </form>
                        </li>
                    @empty
                        <li class="px-5 py-10 text-center text-sm text-gray-400">No pending sent requests.</li>
                    @endforelse
                </ul>
            </div>
        </section>
    </div>
</x-layouts.app>
