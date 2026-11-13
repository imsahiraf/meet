<x-layouts.app title="New message - Meet">
    <div class="flex flex-col gap-6 lg:flex-row">
        @include('mailbox::partials.nav')
        <section class="min-w-0 flex-1">
            @if (session('status'))<div class="mb-4 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>@endif
            @if ($errors->any())<div class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
            <form method="POST" action="{{ route('mail.send') }}" enctype="multipart/form-data" class="space-y-3 rounded-2xl border border-gray-100 bg-white p-5 shadow-sm" id="compose">
                @csrf
                @if ($draft)<input type="hidden" name="draft_id" value="{{ $draft->id }}">@endif
                @if (!empty($prefill['reply_id']))<input type="hidden" name="reply_id" value="{{ $prefill['reply_id'] }}">@endif
                @if (!empty($prefill['forward_id']))<input type="hidden" name="forward_id" value="{{ $prefill['forward_id'] }}">@endif
                <label class="block text-sm font-medium text-gray-700">{{ __('From') }}
                    <select name="account_id" class="mt-1">
                        @foreach ($accounts as $a)<option value="{{ $a->id }}" @selected(old('account_id', $prefill['account_id']) == $a->id)>{{ $a->label() }}</option>@endforeach
                    </select>
                </label>
                <label class="block text-sm font-medium text-gray-700">{{ __('To') }}
                    <input name="to" value="{{ old('to', $prefill['to']) }}" list="mail-contacts" autocomplete="off" data-suggest class="mt-1" placeholder="name@example.com, ...">
                </label>
                @if ($groups->isNotEmpty())
                    <select class="!w-auto text-sm" aria-label="{{ __('Add a contact group to To') }}" onchange="if (this.value) { var t = this.form.elements['to']; t.value = (t.value.trim() ? t.value.trim().replace(/,$/, '') + ', ' : '') + this.value; this.value = ''; }">
                        <option value="">{{ __('Add a group to To...') }}</option>@foreach ($groups as $g)<option value="{{ $g->emails }}">{{ $g->name }}</option>@endforeach
                    </select>
                @endif
                <details @if (old('cc', $prefill['cc'])) open @endif>
                    <summary class="cursor-pointer text-sm text-brand-700">{{ __('Cc / Bcc') }}</summary>
                    <label class="mt-2 block text-sm font-medium text-gray-700">{{ __('Cc') }}<input name="cc" value="{{ old('cc', $prefill['cc']) }}" list="mail-contacts" data-suggest class="mt-1"></label>
                    <label class="mt-2 block text-sm font-medium text-gray-700">{{ __('Bcc') }}<input name="bcc" value="{{ old('bcc') }}" list="mail-contacts" data-suggest class="mt-1"></label>
                </details>
                <datalist id="mail-contacts"></datalist>
                <label class="block text-sm font-medium text-gray-700">{{ __('Subject') }}<input name="subject" value="{{ old('subject', $prefill['subject']) }}" class="mt-1"></label>

                <div>
                    <div class="mb-1 flex flex-wrap gap-1" role="toolbar" aria-label="{{ __('Formatting') }}">
                        @foreach (['bold' => 'B', 'italic' => 'I', 'underline' => 'U', 'insertUnorderedList' => '• ' . __('List'), 'insertOrderedList' => '1. ' . __('List'), 'createLink' => __('Link'), 'removeFormat' => __('Clear')] as $cmd => $label)
                            <button type="button" data-cmd="{{ $cmd }}" class="rounded-md border border-gray-200 px-2 py-1 text-sm text-gray-700 hover:bg-gray-50">{{ $label }}</button>
                        @endforeach
                        <span class="ml-auto inline-flex flex-wrap gap-1" aria-label="{{ __('Help me write') }}">
                            @foreach (['fix' => __('Fix spelling'), 'polite' => __('More polite'), 'shorter' => __('Shorter'), 'formal' => __('Formal')] as $mode => $label)
                                <button type="button" data-write="{{ $mode }}" class="rounded-md border border-brand-200 px-2 py-1 text-sm text-brand-700 hover:bg-brand-50">{{ $label }}</button>
                            @endforeach
                        </span>
                    </div>
                    <p id="write-msg" class="hidden text-xs text-red-600" role="status"></p>
                    <div id="editor" contenteditable="true" class="min-h-[240px] rounded-xl border border-gray-200 p-3 text-sm leading-relaxed focus:outline-none" style="overflow-wrap:anywhere">{!! old('body', $prefill['body'] ?: ($signature ? '<br><br>--<br>' . nl2br(e($signature)) : '')) !!}</div>
                    <input type="hidden" name="body" id="body">
                </div>

                <label class="block text-sm font-medium text-gray-700">{{ __('Attachments') }}
                    <input type="file" name="files[]" multiple class="mt-1 block w-full text-sm">
                    <span class="text-xs text-gray-500">{{ __('Up to :n MB in total.', ['n' => config('mailbox.max_message_mb', 20)]) }}</span>
                </label>

                <div class="flex flex-wrap gap-2 pt-2">
                    <button class="rounded-xl bg-brand-600 px-5 py-2 text-sm font-semibold text-white">{{ __('Send') }}</button>
                    <button name="save" value="draft" class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700">{{ __('Save draft') }}</button>
                    <details class="relative">
                        <summary class="cursor-pointer list-none rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700">{{ __('Send later') }}</summary>
                        <div class="mt-2 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-3">
                            <label class="text-sm text-gray-700">{{ __('Send at') }}<input type="datetime-local" name="send_at" value="{{ old('send_at') }}" min="{{ now()->format('Y-m-d\TH:i') }}" class="mt-1"></label>
                            <button class="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Schedule') }}</button>
                        </div>
                    </details>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" name="receipt" value="1" @checked(old('receipt'))> {{ __('Ask for a read receipt') }}</label>
                    <a href="{{ route('mail.index') }}" class="rounded-xl px-4 py-2 text-sm text-gray-600">{{ __('Discard') }}</a>
                </div>
            </form>
        </section>
    </div>
    <script>
        (function () {
            var ed = document.getElementById('editor');
            document.querySelectorAll('[data-cmd]').forEach(function (b) {
                b.addEventListener('click', function () {
                    var c = b.dataset.cmd, v = null;
                    if (c === 'createLink') { v = prompt('https://'); if (!v) return; if (!/^https?:\/\//.test(v)) v = 'https://' + v; }
                    document.execCommand(c, false, v); ed.focus();
                });
            });
            document.getElementById('compose').addEventListener('submit', function () { document.getElementById('body').value = ed.innerHTML; });
            var wmsg = document.getElementById('write-msg');
            document.querySelectorAll('[data-write]').forEach(function (b) {
                b.addEventListener('click', function () {
                    b.disabled = true; wmsg.classList.add('hidden');
                    fetch(@json(route('mail.write-help')), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                        body: JSON.stringify({ mode: b.dataset.write, html: ed.innerHTML }) })
                        .then(function (r) { return r.json().then(function (d) { if (!r.ok) throw new Error(d.message || 'Not possible now.'); return d; }); })
                        .then(function (d) { ed.innerHTML = d.html; })
                        .catch(function (e) { wmsg.textContent = e.message; wmsg.classList.remove('hidden'); })
                        .finally(function () { b.disabled = false; });
                });
            });
            var list = document.getElementById('mail-contacts'), t;
            document.querySelectorAll('[data-suggest]').forEach(function (inp) {
                inp.addEventListener('input', function () {
                    clearTimeout(t);
                    var last = inp.value.split(/[,;]/).pop().trim();
                    if (last.length < 2) return;
                    t = setTimeout(function () {
                        fetch('{{ route('mail.contacts') }}?q=' + encodeURIComponent(last), { headers: { Accept: 'application/json' } })
                            .then(function (r) { return r.json(); }).then(function (rows) {
                                var prefix = inp.value.slice(0, inp.value.length - inp.value.split(/[,;]/).pop().length);
                                list.innerHTML = '';
                                rows.forEach(function (c) { var o = document.createElement('option'); o.value = prefix + (prefix ? ' ' : '') + c.email; o.label = c.name || c.email; list.appendChild(o); });
                            });
                    }, 200);
                });
            });
        })();
    </script>
</x-layouts.app>
