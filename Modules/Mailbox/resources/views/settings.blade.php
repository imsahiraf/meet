@php $mb = fn ($b) => round($b / 1048576, 1) . ' MB'; @endphp
<x-layouts.app title="Mail settings - Meet">
    <div class="flex flex-col gap-6 lg:flex-row">
        @include('mailbox::partials.nav')
        <section class="min-w-0 flex-1 space-y-6">
            @if (session('status'))<div class="rounded-xl bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif

            <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('Your addresses') }}</h2>
                @forelse ($accounts as $a)
                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-50 pt-3 text-sm">
                        <div class="min-w-0">
                            <div class="break-all font-medium text-gray-900">{{ $a->email }} @if ($a->is_default)<span class="rounded bg-brand-50 px-2 text-xs text-brand-700">{{ __('default') }}</span>@endif</div>
                            <div class="text-xs text-gray-500">
                                {{ $a->kind === 'local' ? __('Meet address') : ($a->kind === 'google' ? __('Gmail (Google connected)') : ($a->kind === 'insights' ? __('Company mailbox (Meet Insights)') : ($presets[$a->provider]['label'] ?? 'IMAP'))) }}
                                @if ($a->last_sync_at) · {{ __('synced :t', ['t' => $a->last_sync_at->diffForHumans()]) }}@endif
                                @if ($a->last_error)<span class="text-red-600"> · {{ $a->last_error }}</span>@endif
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($a->kind !== 'local')<form method="POST" action="{{ route('mail.settings.accounts.sync', $a->id) }}">@csrf<button class="rounded-lg border border-gray-200 px-3 py-1">{{ __('Sync now') }}</button></form>@endif
                            @unless ($a->is_default)<form method="POST" action="{{ route('mail.settings.accounts.default', $a->id) }}">@csrf<button class="rounded-lg border border-gray-200 px-3 py-1">{{ __('Make default') }}</button></form>@endunless
                            <form method="POST" action="{{ route('mail.settings.accounts.remove', $a->id) }}" onsubmit="return confirm('{{ __('Remove this account and its mail from Meet?') }}')">@csrf @method('DELETE')<button class="rounded-lg px-3 py-1 text-red-600 hover:bg-red-50">{{ __('Remove') }}</button></form>
                        </div>
                    </div>
                @empty
                    <p class="mt-2 text-sm text-gray-500">{{ __('No address yet.') }}</p>
                @endforelse

                @if ($insightsReady)
                    <form method="POST" action="{{ route('mail.settings.insights') }}" class="mt-5 rounded-xl bg-gray-50 p-4">
                        @csrf
                        <h3 class="text-sm font-semibold text-gray-900">{{ __('Company mailboxes') }}</h3>
                        <p class="text-xs text-gray-500">{{ __('Read and answer the mailboxes your organization gave you in Meet Insights (your company domain) here in Meet. Login used: :e', ['e' => $insightsLogin]) }}</p>
                        @error('insights')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                        <button class="mt-3 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">{{ $accounts->where('kind', 'insights')->count() ? __('Check for new company mailboxes') : __('Add my company mailboxes') }}</button>
                    </form>
                @endif

                @php $own = $accounts->where('kind', 'local')->count(); @endphp
                @if ($domain && $own < \Modules\Mailbox\Services\Addresses::MAX)
                    <form method="POST" action="{{ route('mail.settings.address') }}" class="mt-5 rounded-xl bg-gray-50 p-4">
                        @csrf
                        <h3 class="text-sm font-semibold text-gray-900">{{ $own ? __('Add another address (alias)') : __('Get your free Meet address') }}</h3>
                        <p class="text-xs text-gray-500">{{ $own ? __('Mail to it comes to you too, and you can send from it (choose it in From). Up to :n addresses.', ['n' => \Modules\Mailbox\Services\Addresses::MAX]) : __(':n MB free. Connect Google to keep older mail in your Google Drive.', ['n' => config('mailbox.free_quota_mb')]) }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input name="local" value="{{ old('local') }}" required pattern="[A-Za-z0-9._-]{3,30}" class="!w-56" placeholder="yourname">
                            <span class="text-sm text-gray-600">{{ \Modules\Mailbox\Services\Addresses::suffix() }}@{{ $domain }}</span>
                            <button class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Create') }}</button>
                        </div>
                    </form>
                @endif
            </div>

            @if ($server && $domain)
                @php $st = $server['status']; $ok = ($st['status'] ?? '') === 'ok'; @endphp
                <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" id="server">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Mail server (admins)') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ __('Mail sent to an address such as :a reaches Meet only when the domain\'s catch-all (cPanel "Default Address") is piped to Meet. Meet sets it by itself on every update and every night.', ['a' => 'name' . $server['suffix'] . '@' . $domain]) }}</p>
                    <ul class="mt-3 space-y-1 text-sm">
                        <li class="break-words"><span class="{{ $ok ? 'text-green-700' : 'text-red-600' }}">{{ $ok ? __('Working') : ($st ? __('Not working') : __('Not checked yet')) }}</span> · {{ $domain }}@if ($st) · {{ $st['message'] }} <span class="text-gray-500">({{ $st['at'] }})</span>@endif</li>
                        @foreach ($server['aliases'] as $alias)
                            @php $a = $st['aliases'][$alias] ?? null; @endphp
                            <li class="break-words"><span class="{{ ($a['status'] ?? '') === 'ok' ? 'text-green-700' : 'text-red-600' }}">{{ ($a['status'] ?? '') === 'ok' ? __('Working') : __('Not working') }}</span> · {{ $alias }} ({{ __('same mailboxes') }})@if ($a) · {{ $a['message'] }}@endif</li>
                        @endforeach
                        <li class="text-gray-600">{{ __('Last mail received through it') }}: {{ $server['last'] ? $server['last']->diffForHumans() : __('none yet') }}</li>
                    </ul>
                    @if (!$ok)
                        <div class="mt-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                            <p class="font-medium">{{ __('How to fix it (once):') }}</p>
                            <ol class="mt-1 list-decimal space-y-1 pl-5">
                                <li>{{ __('cPanel > Security > Manage API Tokens > Create: name it "meet", no expiry. Copy the token.') }}</li>
                                <li>{{ __('Put it in Meet\'s .env as CPANEL_API_TOKEN, and the cPanel user name as CPANEL_USER (in Meet Insights: Control center > Environment > Meet).') }}</li>
                                <li>{{ __('Press "Set it now" below. The line above turns green.') }}</li>
                            </ol>
                            <p class="mt-2">{{ __('Without a token: cPanel > Email > Default Address > domain :d > Advanced Options > Pipe to a Program, and type exactly:', ['d' => $domain]) }}
                                <code class="block select-all rounded bg-white px-2 py-1" style="overflow-wrap:anywhere">meet-mail-pipe.php</code>
                                {{ __('(the file is :f, made by Meet itself; it needs no shell). Then Change. Outgoing mail keeps going out through the existing info@ mailbox.', ['f' => \Modules\Mailbox\Services\CpanelMail::home() . '/meet-mail-pipe.php']) }}</p>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('mail.settings.server') }}" class="mt-3">@csrf<button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white">{{ __('Set it now') }}</button></form>
                </div>
            @endif

            {{-- phone mail apps (Gmail, Outlook, iPhone Mail) over Exchange ActiveSync: one app password per phone (ported from Meet Insights 2026-11-10) --}}
            <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" id="phones">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('Mail on your phone') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('Add your Meet address to Gmail, Outlook or iPhone Mail as an "Exchange" account: e-mail = your address, server = :h, password = an app password made here (one per phone, never your Meet password).', ['h' => request()->getHost()]) }}</p>
                @if (session('app_password'))<p class="mt-3 rounded-lg bg-green-50 p-3 text-sm text-green-800">{{ __('Your new app password (shown only now):') }} <b class="font-mono text-base">{{ session('app_password') }}</b></p>@endif
                @foreach (\Modules\Mailbox\Models\MailAppPassword::where('user_id', auth()->id())->orderBy('id')->get() as $ap)
                    <div class="mt-2 flex items-center justify-between gap-3 border-t border-gray-100 pt-2 text-sm"><span>{{ $ap->name }} <span class="text-gray-500">(...{{ $ap->hint }})</span></span>
                        <form method="POST" action="{{ route('mail.settings.app_passwords.remove', $ap->id) }}">@csrf<button class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm">{{ __('Remove') }}</button></form></div>
                @endforeach
                <form method="POST" action="{{ route('mail.settings.app_passwords.add') }}" class="mt-3 flex flex-wrap gap-2">@csrf
                    <input name="name" required maxlength="80" placeholder="{{ __('Name of the phone, e.g. My Android') }}" class="min-w-0 flex-1 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                    <button class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white">{{ __('Make an app password') }}</button></form>
            </div>

            <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" id="google">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('Google (free)') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('Connect once: your Gmail comes into Meet, older Meet mail moves to a "Meet Mail" folder in your Google Drive (so your mailbox never fills up) and your Google contacts become address suggestions. Everything syncs every hour.') }}</p>
                @if ($settings->google_email)
                    <p class="mt-3 text-sm">{{ __('Connected as :e', ['e' => $settings->google_email]) }} @if ($settings->google_synced_at)<span class="text-gray-500">· {{ __('last sync :t', ['t' => $settings->google_synced_at->diffForHumans()]) }}</span>@endif</p>
                    @if ($settings->google_error)<p class="text-sm text-red-600">{{ $settings->google_error }}</p>@endif
                    <div class="mt-3 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('mail.google.sync') }}">@csrf<button class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm">{{ __('Sync now') }}</button></form>
                        <a href="{{ route('mail.google.connect') }}" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm">{{ __('Connect again') }}</a>
                        <form method="POST" action="{{ route('mail.google.disconnect') }}" onsubmit="return confirm('{{ __('Disconnect Google?') }}')">@csrf<button class="rounded-lg px-3 py-1.5 text-sm text-red-600 hover:bg-red-50">{{ __('Disconnect') }}</button></form>
                    </div>
                @elseif ($googleReady)
                    <a href="{{ route('mail.google.connect') }}" class="mt-3 inline-flex rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Connect Google') }}</a>
                @else
                    <p class="mt-3 text-sm text-amber-700">{{ __('Google is not set up on this server yet (administrator: GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).') }}</p>
                @endif
                <p class="mt-4 text-sm text-gray-600">{{ __('Storage') }}: {{ $mb($quota['used']) }} / {{ $mb($quota['quota']) }} @if ($settings->plan)<span class="text-gray-500">({{ $settings->plan }})</span>@endif</p>
            </div>

            <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-semibold text-gray-900">{{ __('Add an e-mail account') }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ __('Read and send mail of Gmail, Yahoo, Rediffmail, Zoho, Outlook or any IMAP / SMTP account here. Your password is stored encrypted.') }}</p>
                <form method="POST" action="{{ route('mail.settings.accounts.add') }}" class="mt-3 grid gap-3 sm:grid-cols-2" id="add-account">
                    @csrf
                    <label class="text-sm font-medium text-gray-700">{{ __('Provider') }}
                        <select name="provider" class="mt-1" data-presets='@json($presets)'>
                            @foreach ($presets as $k => $p)<option value="{{ $k }}" @selected(old('provider') === $k)>{{ $p['label'] }}</option>@endforeach
                        </select>
                    </label>
                    <label class="text-sm font-medium text-gray-700">{{ __('E-mail address') }}<input name="email" type="email" required value="{{ old('email') }}" class="mt-1"></label>
                    <label class="text-sm font-medium text-gray-700">{{ __('Password or app password') }}<input name="password" type="password" required class="mt-1" autocomplete="new-password"></label>
                    <label class="text-sm font-medium text-gray-700">{{ __('Your name (optional)') }}<input name="display_name" value="{{ old('display_name') }}" class="mt-1"></label>
                    <p class="text-xs text-gray-500 sm:col-span-2" data-help></p>
                    <details class="sm:col-span-2" data-servers>
                        <summary class="cursor-pointer text-sm text-brand-700">{{ __('Server settings') }}</summary>
                        <div class="mt-2 grid gap-3 sm:grid-cols-3">
                            <label class="text-sm">{{ __('IMAP server') }}<input name="imap_host" value="{{ old('imap_host') }}" class="mt-1"></label>
                            <label class="text-sm">{{ __('Port') }}<input name="imap_port" type="number" value="{{ old('imap_port') }}" class="mt-1"></label>
                            <label class="text-sm">{{ __('Security') }}<select name="imap_security" class="mt-1"><option value="">auto</option><option>ssl</option><option>tls</option><option>none</option></select></label>
                            <label class="text-sm">{{ __('SMTP server') }}<input name="smtp_host" value="{{ old('smtp_host') }}" class="mt-1"></label>
                            <label class="text-sm">{{ __('Port') }}<input name="smtp_port" type="number" value="{{ old('smtp_port') }}" class="mt-1"></label>
                            <label class="text-sm">{{ __('Security') }}<select name="smtp_security" class="mt-1"><option value="">auto</option><option>ssl</option><option>tls</option><option>none</option></select></label>
                            <label class="text-sm sm:col-span-3">{{ __('User name (if not the e-mail address)') }}<input name="username" value="{{ old('username') }}" class="mt-1"></label>
                        </div>
                    </details>
                    <div class="sm:col-span-2"><button class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Test and add') }}</button></div>
                </form>
            </div>

            <form method="POST" action="{{ route('mail.settings.save') }}" class="space-y-4 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                @csrf
                <h2 class="text-lg font-semibold text-gray-900">{{ __('Signature and away message') }}</h2>
                <label class="block text-sm font-medium text-gray-700">{{ __('Signature') }}<textarea name="signature" rows="3" class="mt-1">{{ old('signature', $settings->signature) }}</textarea></label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="vacation_on" value="1" @checked($settings->vacation_on)> {{ __('Send an automatic reply (once per sender every 4 days)') }}</label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="text-sm">{{ __('Subject') }}<input name="vacation_subject" value="{{ old('vacation_subject', $settings->vacation_subject) }}" class="mt-1"></label>
                    <label class="text-sm">{{ __('Until') }}<input type="date" name="vacation_until" value="{{ old('vacation_until', $settings->vacation_until?->toDateString()) }}" class="mt-1"></label>
                </div>
                <label class="block text-sm">{{ __('Message') }}<textarea name="vacation_body" rows="3" class="mt-1">{{ old('vacation_body', $settings->vacation_body) }}</textarea></label>
                <label class="block text-sm font-medium text-gray-700">{{ __('Mail on your phone: also send a copy of every new mail to') }}
                    <input type="email" name="forward_to" value="{{ old('forward_to', $settings->forward_to) }}" placeholder="you@gmail.com" class="mt-1">
                    <span class="block text-xs font-normal text-gray-500">{{ __('Your phone\'s mail app (Gmail, Outlook, Apple Mail) then shows your Meet mail without any server settings. Or use the Meet app itself. Leave empty for no copies.') }}</span>
                    @error('forward_to')<span class="block text-xs text-red-600">{{ $message }}</span>@enderror</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="drive_archive" value="1" @checked($settings->drive_archive)> {{ __('Move older mail to my Google Drive') }}</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="contacts_sync" value="1" @checked($settings->contacts_sync)> {{ __('Use my Google contacts as suggestions') }}</label>
                <label class="flex items-center gap-2 text-sm"><input type="hidden" name="conversations" value="0"><input type="checkbox" name="conversations" value="1" @checked($settings->conversations ?? true)> {{ __('Conversation view (a conversation shows once in the list, with its count)') }}</label>
                <label class="block text-sm">{{ __('Undo send') }}
                    <select name="undo_seconds" class="mt-1 !w-auto">@foreach ([0 => __('Off (send at once)'), 10 => __('10 seconds'), 20 => __('20 seconds'), 30 => __('30 seconds')] as $k => $l)<option value="{{ $k }}" @selected((int) old('undo_seconds', $settings->undo_seconds ?? 0) === $k)>{{ $l }}</option>@endforeach</select>
                    <span class="block text-xs text-gray-500">{{ __('Mail waits this long (up to a minute more) with an Undo button before it leaves.') }}</span></label>
                <button class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Save') }}</button>
            </form>

            <div class="grid gap-6 xl:grid-cols-2">
                <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Filters') }}</h2>
                    @foreach ($filters as $f)
                        <div class="mt-2 flex items-center justify-between gap-2 text-sm">
                            <span class="min-w-0 break-words">{{ __(ucfirst($f->field)) }} {{ __('contains') }} "{{ $f->contains }}" → {{ $f->action }} {{ $f->value }}</span>
                            <form method="POST" action="{{ route('mail.settings.filters.remove', $f->id) }}">@csrf @method('DELETE')<button class="text-red-600">×</button></form>
                        </div>
                    @endforeach
                    <form method="POST" action="{{ route('mail.settings.filters.add') }}" class="mt-3 grid gap-2 sm:grid-cols-2">
                        @csrf
                        <select name="field"><option value="from">{{ __('From') }}</option><option value="to">{{ __('To') }}</option><option value="subject">{{ __('Subject') }}</option><option value="body">{{ __('Message text') }}</option><option value="any">{{ __('Anywhere') }}</option><option value="attachment">{{ __('Attachment name (* = any attachment)') }}</option></select>
                        <input name="contains" required placeholder="{{ __('contains...') }}">
                        <select name="action"><option value="label">{{ __('Add label') }}</option><option value="folder">{{ __('Move to (archive / spam / trash)') }}</option><option value="star">{{ __('Star') }}</option><option value="read">{{ __('Mark read') }}</option></select>
                        <input name="value" placeholder="{{ __('label or folder') }}">
                        <button class="rounded-xl border border-gray-200 px-4 py-2 text-sm sm:col-span-2">{{ __('Add filter') }}</button>
                    </form>
                </div>
                <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Share my mailbox') }}</h2>
                    <p class="text-xs text-gray-500">{{ __('A colleague (an assistant, a stand-in) can read your mail and send from your addresses until you stop it. They open it from their mail menu.') }}</p>
                    @foreach ($delegates as $d)
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-gray-50 pt-2 text-sm"><span class="min-w-0 break-words"><strong>{{ $d->full_name }}</strong> <span class="text-gray-500">{{ $d->email }}</span></span>
                            <form method="POST" action="{{ route('mail.settings.delegates.remove', $d->id) }}">@csrf @method('DELETE')<button class="text-xs text-red-600">{{ __('Stop sharing') }}</button></form></div>
                    @endforeach
                    <form method="POST" action="{{ route('mail.settings.delegates.add') }}" class="mt-3 flex flex-wrap gap-2">
                        @csrf
                        <input type="email" name="email" required class="min-w-0 flex-1" placeholder="{{ __('Their Meet sign-in e-mail') }}">
                        <button class="rounded-xl border border-gray-200 px-4 py-2 text-sm">{{ __('Share') }}</button>
                    </form>
                </div>
                <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Contact groups') }}</h2>
                    <p class="text-xs text-gray-500">{{ __('A name for several addresses (team, family, clients ...). When writing a mail, add the whole group to To in one go.') }}</p>
                    @foreach ($groups as $g)
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 border-t border-gray-50 pt-2 text-sm"><span class="min-w-0 break-words"><strong>{{ $g->name }}</strong> <span class="text-gray-500">{{ $g->emails }}</span></span>
                            <form method="POST" action="{{ route('mail.settings.groups.remove', $g->id) }}">@csrf @method('DELETE')<button class="text-xs text-red-600">{{ __('Remove') }}</button></form></div>
                    @endforeach
                    <form method="POST" action="{{ route('mail.settings.groups.add') }}" class="mt-3 grid gap-2">
                        @csrf
                        <input name="name" required maxlength="60" placeholder="{{ __('Group name') }}">
                        <textarea name="emails" rows="2" required placeholder="a@example.com, b@example.com"></textarea>
                        <button class="justify-self-start rounded-xl border border-gray-200 px-4 py-2 text-sm">{{ __('Save group') }}</button>
                    </form>
                </div>
                <div class="rounded-2xl border border-gray-100 bg-white p-5 shadow-sm">
                    <h2 class="text-lg font-semibold text-gray-900">{{ __('Labels') }}</h2>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($labels as $l)
                            <form method="POST" action="{{ route('mail.settings.labels.remove', $l->id) }}">@csrf @method('DELETE')
                                <button class="rounded px-2 py-1 text-xs text-white" style="background: {{ $l->color }}">{{ $l->name }} ×</button></form>
                        @endforeach
                    </div>
                    <form method="POST" action="{{ route('mail.settings.labels.add') }}" class="mt-3 flex flex-wrap gap-2">
                        @csrf
                        <input name="name" required class="min-w-0 flex-1" placeholder="{{ __('New label') }}">
                        <input type="color" name="color" value="#6366f1" class="!h-10 !w-14 !p-1">
                        <button class="rounded-xl border border-gray-200 px-4 py-2 text-sm">{{ __('Add') }}</button>
                    </form>
                </div>
            </div>
        </section>
    </div>
    <script>
        (function () {
            var sel = document.querySelector('[data-presets]'); if (!sel) return;
            var presets = JSON.parse(sel.dataset.presets), help = document.querySelector('[data-help]'), servers = document.querySelector('[data-servers]');
            function show() { var p = presets[sel.value]; help.textContent = p.help || ''; if (sel.value === 'other') servers.open = true; }
            sel.addEventListener('change', show); show();
        })();
    </script>
</x-layouts.app>
