<x-layouts.app title="Mail - Meet">
    <div class="flex flex-col gap-6 lg:flex-row">
        @include('mailbox::partials.nav')

        <section class="min-w-0 flex-1">
            @if (session('status'))<div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}
                @if (session('undo'))<form method="POST" action="{{ route('mail.act', session('undo')) }}">@csrf<input type="hidden" name="action" value="cancel"><button class="rounded-lg bg-white px-3 py-1 font-semibold text-brand-700">{{ __('Undo') }}</button></form>@endif
            </div>@endif
            @if ($accounts->isEmpty())
                <div class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                    <h1 class="text-xl font-semibold text-gray-900">{{ __('Welcome to Meet Mail') }}</h1>
                    <p class="mt-2 text-sm text-gray-600">{{ __('Get a free Meet address, or bring your Gmail, Yahoo, Rediffmail, Zoho or Outlook account and read everything in one place.') }}</p>
                    <a href="{{ route('mail.settings') }}" class="mt-4 inline-flex rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white">{{ __('Set up mail') }}</a>
                </div>
            @else
                <form method="GET" action="{{ route('mail.index') }}" class="mb-3 flex flex-wrap gap-2">
                    <input type="hidden" name="folder" value="{{ $folder }}">
                    <input type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search mail (from:riya has:attachment is:unread in:anywhere)') }}" class="min-w-0 flex-1" data-search>
                    <button class="rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700">{{ __('Search') }}</button>
                </form>

                <form method="POST" action="{{ route('mail.bulk') }}" id="bulk">
                    @csrf
                    <div class="mb-2 flex flex-wrap items-center gap-2 text-sm">
                        <label class="flex items-center gap-2 rounded-lg px-2 py-1 text-gray-600"><input type="checkbox" data-all> {{ __('All') }}</label>
                        @foreach (['read' => __('Mark read'), 'unread' => __('Mark unread'), 'star' => __('Star'), 'archive' => __('Archive'), 'spam' => __('Spam'), 'delete' => $folder === 'trash' ? __('Delete forever') : __('Delete')] as $act => $label)
                            <button name="action" value="{{ $act }}" class="rounded-lg border border-gray-200 bg-white px-3 py-1 text-gray-700 hover:bg-gray-50">{{ $label }}</button>
                        @endforeach
                        @if ($labels->isNotEmpty())
                            <select name="value" class="!w-auto !py-1 text-sm" onchange="if(this.value){this.form.querySelector('[name=action][value=label]').click()}">
                                <option value="">{{ __('Label as...') }}</option>
                                @foreach ($labels as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
                            </select>
                            <button name="action" value="label" hidden></button>
                        @endif
                        @if ($folder === 'trash' && $messages->total())
                            <button form="empty-trash" class="ml-auto rounded-lg px-3 py-1 font-medium text-red-600 hover:bg-red-50">{{ __('Empty trash') }}</button>
                        @endif
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-gray-100 bg-white shadow-sm">
                        @forelse ($messages as $m)
                            <div data-row class="flex items-start gap-3 border-b border-gray-50 px-3 py-3 last:border-0 {{ $m->seen ? '' : 'bg-brand-50/40' }}">
                                <input type="checkbox" name="ids[]" value="{{ $m->id }}" class="mt-1" aria-label="{{ __('Select') }}">
                                <button formaction="{{ route('mail.act', $m->id) }}" name="action" value="{{ $m->starred ? 'unstar' : 'star' }}" data-star
                                    class="mt-0.5 text-lg leading-none {{ $m->starred ? 'text-amber-400' : 'text-gray-300 hover:text-gray-500' }}" aria-label="{{ $m->starred ? __('Unstar') : __('Star') }}">{{ $m->starred ? '★' : '☆' }}</button>
                                <a href="{{ $m->folder === 'drafts' ? route('mail.compose', ['draft' => $m->id]) : route('mail.show', $m->id) }}" class="min-w-0 flex-1">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <span class="truncate text-sm {{ $m->seen ? 'text-gray-700' : 'font-semibold text-gray-900' }}">
                                            {{ in_array($m->folder, ['sent', 'drafts'], true) ? __('To') . ': ' . implode(', ', array_slice($m->to ?? [], 0, 2)) : ($m->from_name ?: $m->from_email) }}
                                        </span>
                                        <span class="shrink-0 text-xs text-gray-500">{{ $m->sent_at?->isToday() ? $m->sent_at->format('H:i') : $m->sent_at?->format('d M') }}</span>
                                    </div>
                                    <div class="truncate text-sm {{ $m->seen ? 'text-gray-600' : 'font-medium text-gray-900' }}">
                                        @if ($m->starred)⭐ @endif{{ $m->subject ?: __('(no subject)') }}@if (($counts[$m->thread_key] ?? 0) > 1) <span class="text-xs font-normal text-gray-500">({{ $counts[$m->thread_key] }})</span>@endif
                                        @if ($m->has_attachments)📎@endif
                                        @if ($m->drive_file_id)<span class="text-xs text-gray-400">· {{ __('in Drive') }}</span>@endif
                                    </div>
                                    <div class="truncate text-xs text-gray-500">
                                        @foreach ($m->labels as $l)<span class="mr-1 rounded px-1.5 py-0.5 text-white" style="background: {{ $l->color }}">{{ $l->name }}</span>@endforeach
                                        {{ $m->snippet }}
                                    </div>
                                </a>
                            </div>
                        @empty
                            <p class="px-5 py-10 text-center text-sm text-gray-500">{{ $search ? __('Nothing matches your search.') : __('No mail here.') }}</p>
                        @endforelse
                    </div>
                </form>
                <form method="POST" action="{{ route('mail.trash.empty') }}" id="empty-trash" onsubmit="return confirm('{{ __('Delete everything in Trash for good?') }}')">@csrf</form>
                <div class="mt-4">{{ $messages->links() }}</div>
            @endif
        </section>
    </div>
    <script>
        document.querySelector('[data-all]')?.addEventListener('change', function () {
            document.querySelectorAll('#bulk input[name="ids[]"]').forEach(c => c.checked = this.checked);
        });
        // keyboard keys like Gmail: j / k move, o or Enter open, x select, e archive, # delete, s star, c write, / search, ? help
        (function () {
            const rows = [...document.querySelectorAll('[data-row]')];
            let i = -1;
            const mark = (n) => { rows.forEach(r => r.style.boxShadow = ''); i = Math.max(0, Math.min(rows.length - 1, n)); if (rows[i]) { rows[i].style.boxShadow = 'inset 3px 0 0 #4f46e5'; rows[i].scrollIntoView({ block: 'nearest' }) } };
            const act = (action) => {
                const form = document.getElementById('bulk');
                if (!form.querySelector('input[name="ids[]"]:checked') && rows[i]) rows[i].querySelector('input[name="ids[]"]').checked = true;
                const b = form.querySelector('button[name=action][value="' + action + '"]'); if (b) b.click();
            };
            document.addEventListener('keydown', (e) => {
                const t = e.target; if (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) || e.ctrlKey || e.metaKey || e.altKey) return;
                if (e.key === 'j') mark(i + 1); else if (e.key === 'k') mark(i - 1);
                else if ((e.key === 'o' || e.key === 'Enter') && rows[i]) rows[i].querySelector('a').click();
                else if (e.key === 'x' && rows[i]) { const c = rows[i].querySelector('input[name="ids[]"]'); c.checked = !c.checked }
                else if (e.key === 'e') act('archive'); else if (e.key === '#') act('delete');
                else if (e.key === 's' && rows[i]) rows[i].querySelector('[data-star]').click();
                else if (e.key === 'c') window.location.href = {{ \Illuminate\Support\Js::from(route('mail.compose')) }};
                else if (e.key === '/') { e.preventDefault(); document.querySelector('[data-search]')?.focus() }
                else if (e.key === '?') alert({{ \Illuminate\Support\Js::from(__('Keys: j / k next / previous, o open, x select, e archive, # delete, s star, c write, / search')) }});
            });
        })();
    </script>
</x-layouts.app>
