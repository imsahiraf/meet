<x-layouts.app title="Insights by Meet - Meet">
    <div class="mx-auto max-w-2xl space-y-4">
        <h1 class="text-2xl font-semibold text-gray-900">Insights by Meet</h1>
        @if (session('status'))<div class="rounded-xl bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>@endif
        @if ($errors->any())<div class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
        <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
            <p class="text-sm text-gray-700">Use Meet with your Insights by Meet login. If you already had this Meet account before your organization started using Insights, link the two: opening Meet from Insights then signs you in to this account, with your chats, contacts and mail.</p>
            @forelse ($links as $l)
                <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-50 pt-3 text-sm">
                    <span class="break-all">{{ $l->insights_email }} @if ($l->org)<span class="text-gray-500">({{ $l->org }})</span>@endif</span>
                    <form method="POST" action="{{ route('insights.unlink', $l->id) }}">@csrf @method('DELETE')<button class="rounded-lg px-3 py-1 text-red-600 hover:bg-red-50">Remove link</button></form>
                </div>
            @empty
                <p class="mt-3 text-sm text-gray-500">No Insights login is linked yet.</p>
            @endforelse
            <a href="{{ $linkUrl }}" class="mt-4 inline-flex rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white">Link my Insights login</a>
            <p class="mt-2 text-xs text-gray-500">You sign in to Insights, confirm, and come back here.</p>
        </div>
    </div>
</x-layouts.app>
