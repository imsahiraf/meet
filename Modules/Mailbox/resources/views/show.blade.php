@php
    $csp = $images ? "default-src 'none'; img-src * data: cid:; style-src 'unsafe-inline'; font-src *" : "default-src 'none'; img-src data:; style-src 'unsafe-inline'";
    $doc = $body['html']
        ? '<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . $csp . '"><base target="_blank"><style>body{font:14px/1.5 system-ui,sans-serif;margin:0;color:#111;word-wrap:break-word}img{max-width:100%;height:auto}table{max-width:100%}</style>' . $body['html']
        : null;
    $hasRemote = $body['html'] && preg_match('/<img[^>]+src=["\']?https?:/i', $body['html']);
    $act = function ($action, $label, $value = null, $cls = '') use ($m) {
        return '<form method="POST" action="' . route('mail.act', $m->id) . '" class="inline">' . csrf_field()
            . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="from" value="' . e($m->folder) . '">'
            . ($value !== null ? '<input type="hidden" name="value" value="' . e($value) . '">' : '')
            . '<button class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 ' . $cls . '">' . e($label) . '</button></form>';
    };
@endphp
<x-layouts.app :title="($m->subject ?: 'Mail') . ' - Meet'">
    <div class="flex flex-col gap-6 lg:flex-row">
        @include('mailbox::partials.nav')
        <section class="min-w-0 flex-1">
            @if (session('status'))<div class="mb-4 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>@endif
            <div class="mb-3 flex flex-wrap gap-2">
                <a href="{{ route('mail.index', ['folder' => $m->folder]) }}" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700">← {{ __('Back') }}</a>
                <a href="{{ route('mail.compose', ['reply' => $m->id]) }}" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-semibold text-white">{{ __('Reply') }}</a>
                <a href="{{ route('mail.compose', ['reply' => $m->id, 'all' => 1]) }}" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700">{{ __('Reply all') }}</a>
                <a href="{{ route('mail.compose', ['forward' => $m->id]) }}" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm text-gray-700">{{ __('Forward') }}</a>
                {!! $act($m->starred ? 'unstar' : 'star', $m->starred ? __('Unstar') : __('Star')) !!}
                {!! $act('unread', __('Mark unread')) !!}
                @if ($m->folder !== 'archive'){!! $act('archive', __('Archive')) !!}@else{!! $act('inbox', __('Move to Inbox')) !!}@endif
                @if ($m->folder !== 'spam'){!! $act('spam', __('Spam')) !!}@else{!! $act('inbox', __('Not spam')) !!}@endif
                @if (in_array($m->folder, ['inbox', 'archive'], true))
                    <form method="POST" action="{{ route('mail.act', $m->id) }}" class="inline">@csrf<input type="hidden" name="action" value="snooze"><input type="hidden" name="from" value="{{ $m->folder }}">
                        <select name="value" class="!w-auto !py-1.5 text-sm" onchange="this.form.submit()" aria-label="{{ __('Snooze') }}"><option value="">{{ __('Snooze until...') }}</option>
                            <option value="later">{{ __('Later today (3 hours)') }}</option><option value="tomorrow">{{ __('Tomorrow 9:00') }}</option><option value="week">{{ __('Next Monday 9:00') }}</option></select>
                    </form>
                @elseif ($m->folder === 'snoozed')
                    <span class="self-center text-sm text-gray-600">{{ __('Snoozed until :t', ['t' => $m->snooze_until?->format('d M, H:i')]) }}</span>{!! $act('unsnooze', __('Back to Inbox now')) !!}
                @elseif ($m->folder === 'scheduled')
                    <span class="self-center text-sm {{ $m->status === 'failed' ? 'text-red-600' : 'text-gray-600' }}">{{ $m->status === 'failed' ? __('Not sent: :e', ['e' => $m->error]) : __('Sends at :t', ['t' => $m->send_at?->format('d M Y, H:i')]) }}</span>{!! $act('cancel', __('Cancel sending')) !!}
                @endif
                {!! $act('delete', $m->folder === 'trash' ? __('Delete forever') : __('Delete'), null, 'text-red-600') !!}
                @if ($labels->isNotEmpty())
                    <form method="POST" action="{{ route('mail.act', $m->id) }}" class="inline">@csrf<input type="hidden" name="action" value="label">
                        <select name="value" class="!w-auto !py-1.5 text-sm" onchange="this.form.submit()"><option value="">{{ __('Label as...') }}</option>@foreach ($labels as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select>
                    </form>
                @endif
            </div>

            @foreach ($events ?? [] as $ev)
                <div class="mb-3 rounded-2xl border border-brand-100 bg-brand-50 p-4 text-sm">
                    <div class="font-semibold text-gray-900">📅 {{ $ev['method'] === 'CANCEL' ? __('Cancelled:') . ' ' : '' }}{{ $ev['title'] }}</div>
                    <div class="mt-1 text-gray-700">{{ $ev['start'] ?: __('time not given') }}@if ($ev['end']) - {{ $ev['end'] }}@endif</div>
                    @if ($ev['where'])<div class="break-words text-gray-700">{{ __('Where') }}: {{ $ev['where'] }}</div>@endif
                    @if ($ev['organizer'])<div class="break-words text-gray-500">{{ __('From') }}: {{ $ev['organizer'] }}</div>@endif
                    @if ($ev['method'] === 'REQUEST' && $ev['uid'] !== '' && $ev['organizer'])
                        <form method="POST" action="{{ route('mail.rsvp', $m->id) }}" class="mt-2 flex flex-wrap gap-2">@csrf<input type="hidden" name="event" value="{{ $loop->index }}">
                            <button name="answer" value="accept" class="rounded-lg bg-brand-600 px-3 py-1.5 text-white">{{ __('Yes, going') }}</button>
                            <button name="answer" value="tentative" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5">{{ __('Maybe') }}</button>
                            <button name="answer" value="decline" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5">{{ __('No') }}</button>
                        </form>
                    @endif
                    <div class="mt-1 text-xs text-gray-500">{{ __('Open the .ics attachment below to add it to your calendar.') }}</div>
                </div>
            @endforeach
            <article class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                <h1 class="break-words text-xl font-semibold text-gray-900">{{ $m->subject ?: __('(no subject)') }}</h1>
                <div class="mt-1 flex flex-wrap gap-1">
                    @foreach ($m->labels as $l)
                        <form method="POST" action="{{ route('mail.act', $m->id) }}" class="inline">@csrf<input type="hidden" name="action" value="unlabel"><input type="hidden" name="value" value="{{ $l->id }}">
                            <button class="rounded px-2 py-0.5 text-xs text-white" style="background: {{ $l->color }}" title="{{ __('Remove label') }}">{{ $l->name }} ×</button></form>
                    @endforeach
                </div>
                <div class="mt-3 text-sm text-gray-700">
                    <div class="break-words"><strong>{{ $m->from_name ?: $m->from_email }}</strong> <span class="text-gray-500">&lt;{{ $m->from_email }}&gt;</span></div>
                    <div class="break-words text-gray-500">{{ __('To') }}: {{ implode(', ', $m->to ?? []) }}@if ($m->cc) · {{ __('Cc') }}: {{ implode(', ', $m->cc) }}@endif</div>
                    <div class="text-gray-500">{{ $m->sent_at?->format('d M Y, h:i A') }} · {{ $m->account->email }}</div>
                    @php $meta = (array) ($m->send_meta ?? []); @endphp
                    @foreach ($meta['read'] ?? [] as $rd)
                        <div class="text-green-700">{{ __('Read by :w on :t', ['w' => $rd['by'] ?? '', 't' => \Illuminate\Support\Carbon::parse($rd['at'])->format('d M Y, h:i A')]) }}</div>
                    @endforeach
                    @if (($meta['receipt'] ?? null) === 'asked' && empty($meta['read']))<div class="text-gray-500">{{ __('Read receipt asked - not answered yet') }}</div>@endif
                    @if (!empty($meta['bounce']))<div class="text-red-600">{{ __('Not delivered to :t: :w', ['t' => $meta['bounce']['to'] ?? '', 'w' => $meta['bounce']['why'] ?? '']) }}</div>@endif
                </div>

                @if ($archiveError)
                    <div class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ __('This message is kept in your Google Drive and could not be opened: :e', ['e' => $archiveError]) }}
                        <a class="font-semibold" href="https://drive.google.com/file/d/{{ $m->drive_file_id }}/view" target="_blank" rel="noopener">{{ __('Open in Drive') }}</a></div>
                @endif

                <div class="mt-5">
                    @if ($doc)
                        @if ($hasRemote && !$images)
                            <p class="mb-2 text-xs text-gray-500">{{ __('Pictures from the internet are hidden to protect your privacy.') }} <a class="font-semibold text-brand-700" href="{{ route('mail.show', ['id' => $m->id, 'images' => 1]) }}">{{ __('Show pictures') }}</a></p>
                        @endif
                        <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ $doc }}" class="h-[60vh] w-full rounded-lg border border-gray-100" title="{{ __('Message') }}"></iframe>
                    @else
                        <div class="whitespace-pre-wrap break-words text-sm text-gray-800">{{ $body['text'] }}</div>
                    @endif
                </div>

                @if ($m->attachments->isNotEmpty())
                    <div class="mt-5 flex flex-wrap gap-2">
                        @foreach ($m->attachments as $a)
                            @if ($a->path)
                                <a href="{{ route('mail.attachment', $a->id) }}" class="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">📎 {{ $a->filename }} <span class="text-xs text-gray-400">{{ max(1, round($a->size / 1024)) }} KB</span></a>
                            @else
                                <a href="https://drive.google.com/file/d/{{ $m->drive_file_id }}/view" target="_blank" rel="noopener" class="rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">📎 {{ $a->filename }} <span class="text-xs text-gray-400">{{ __('in Drive') }}</span></a>
                            @endif
                        @endforeach
                    </div>
                @endif
            </article>

            @if ($thread->isNotEmpty())
                <h2 class="mb-2 mt-6 text-sm font-semibold text-gray-700">{{ __('In this conversation') }}</h2>
                <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white">
                    @foreach ($thread as $t)
                        <a href="{{ route('mail.show', $t->id) }}" class="block border-b border-gray-50 px-4 py-2 text-sm last:border-0 hover:bg-gray-50">
                            <span class="font-medium text-gray-800">{{ $t->from_name ?: $t->from_email }}</span> <span class="text-xs text-gray-500">{{ $t->sent_at?->format('d M H:i') }}</span>
                            <div class="truncate text-gray-500">{{ $t->snippet }}</div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</x-layouts.app>
