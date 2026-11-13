@php
    $mb = fn ($b) => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
    $icons = ['inbox' => '📥', 'starred' => '⭐', 'sent' => '📤', 'drafts' => '📝', 'scheduled' => '🕒', 'snoozed' => '⏰', 'archive' => '🗄️', 'spam' => '⚠️', 'trash' => '🗑️'];
@endphp
<aside class="w-full shrink-0 lg:w-60">
    <a href="{{ route('mail.compose') }}" class="mb-4 flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700">
        ✏️ {{ __('Compose') }}
    </a>
    @if (!empty($actingAs))
        <div class="mb-3 rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">{{ __('You are in the mailbox of :n.', ['n' => $actingAs->full_name]) }}
            <a href="{{ route('mail.index', ['as' => 0]) }}" class="font-semibold underline">{{ __('Back to mine') }}</a></div>
    @endif
    <nav class="flex flex-wrap gap-1 lg:flex-col" aria-label="{{ __('Mail folders') }}">
        @foreach (\Modules\Mailbox\Models\MailMessage::FOLDERS as $key => $label)
            @php $count = $key === 'drafts' ? ($drafts ?? 0) : ($unread[$key] ?? 0); @endphp
            <a href="{{ route('mail.index', ['folder' => $key]) }}"
               class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm transition {{ ($folder ?? '') === $key && empty($labelId) ? 'bg-brand-50 font-semibold text-brand-700' : 'text-gray-700 hover:bg-gray-100' }}">
                <span>{{ $icons[$key] }} {{ __($label) }}</span>
                @if ($count)<span class="rounded-full bg-gray-200 px-2 text-xs font-semibold text-gray-700">{{ $count }}</span>@endif
            </a>
        @endforeach
    </nav>

    @if (($sharedWithMe ?? collect())->isNotEmpty())
        <div class="mt-4"><div class="px-3 text-xs font-semibold uppercase text-gray-500">{{ __('Shared with me') }}</div>
            @foreach ($sharedWithMe as $o)<a href="{{ route('mail.index', ['as' => $o->id]) }}" class="block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100">📮 {{ $o->full_name }}</a>@endforeach
        </div>
    @endif
    @if (($labels ?? collect())->isNotEmpty())
        <p class="mb-1 mt-5 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Labels') }}</p>
        <nav class="flex flex-wrap gap-1 lg:flex-col">
            @foreach ($labels as $l)
                <a href="{{ route('mail.index', ['label' => $l->id]) }}" class="flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm {{ (int) ($labelId ?? 0) === $l->id ? 'bg-brand-50 font-semibold text-brand-700' : 'text-gray-700 hover:bg-gray-100' }}">
                    <span class="inline-block h-2.5 w-2.5 rounded-full" style="background: {{ $l->color }}"></span>{{ $l->name }}
                </a>
            @endforeach
        </nav>
    @endif

    @if (($accounts ?? collect())->count() > 1)
        <p class="mb-1 mt-5 px-3 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ __('Accounts') }}</p>
        <nav class="flex flex-wrap gap-1 lg:flex-col">
            @foreach ($accounts as $a)
                <a href="{{ route('mail.index', ['account' => $a->id]) }}" class="truncate rounded-lg px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-100" title="{{ $a->email }}">{{ $a->email }}</a>
            @endforeach
        </nav>
    @endif

    @isset($quota)
        <div class="mt-5 hidden rounded-xl border border-gray-100 bg-white p-3 text-xs text-gray-600 lg:block">
            <div class="mb-1 flex justify-between"><span>{{ __('Storage') }}</span><span>{{ $mb($quota['used']) }} / {{ $mb($quota['quota']) }}</span></div>
            <div class="h-2 overflow-hidden rounded-full bg-gray-100"><div class="h-2 rounded-full {{ $quota['pct'] >= 90 ? 'bg-red-500' : 'bg-brand-600' }}" style="width: {{ $quota['pct'] }}%"></div></div>
            @if (!$quota['google'])
                <p class="mt-2">{{ __('Connect Google to keep older mail in your Google Drive and use more space.') }} <a class="font-semibold text-brand-700" href="{{ route('mail.settings') }}#google">{{ __('Connect') }}</a></p>
            @else
                <p class="mt-2">{{ __('Older mail moves to your Google Drive every hour.') }}</p>
            @endif
        </div>
    @endisset
    <a href="{{ route('mail.settings') }}" class="mt-3 block rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-100 {{ ($folder ?? '') === 'settings' ? 'bg-brand-50 font-semibold text-brand-700' : '' }}">⚙️ {{ __('Mail settings') }}</a>
</aside>
