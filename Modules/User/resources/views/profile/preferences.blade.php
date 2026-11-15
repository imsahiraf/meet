<x-layouts.app title="Preferences - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Preferences</h1>
            <p class="mt-1 text-sm text-gray-500">Theme, language, timezone, and media behaviour.</p>
        </div>
        <a href="{{ route('profile.edit') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to settings</a>
    </div>

    <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('profile.preferences') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            @method('PATCH')

            <div>
                <label class="block text-sm font-medium text-gray-700">Theme</label>
                <select name="theme" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('theme', $user->preferences?->theme) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @php $mine = old('accent', $user->preferences?->accent ?: 'indigo'); @endphp
            <fieldset class="sm:col-span-2 min-w-0" data-accents>
                <legend class="block text-sm font-medium text-gray-700">Colour theme <span class="font-normal text-gray-500">(tap one to see it, then Save)</span></legend>
                <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach (\Modules\User\Models\UserPreference::ACCENTS as $key => [$label, $main, $tint])
                        <label class="flex cursor-pointer flex-col gap-2 rounded-xl border-2 p-2 text-sm has-[:checked]:border-brand-600 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-500 border-gray-200">
                            <input type="radio" name="accent" value="{{ $key }}" class="sr-only" @checked($mine === $key)>
                            <span class="grid h-14 grid-cols-2 gap-1 rounded-lg p-1.5" style="background: {{ $tint }}" aria-hidden="true">
                                <span class="col-span-2 rounded" style="background: {{ $main }}"></span><span class="rounded border border-black/5 bg-white"></span><span class="rounded border border-black/5 bg-white"></span>
                            </span>
                            <span class="break-words">{{ $label }}</span>
                        </label>
                    @endforeach
                    <label class="flex cursor-pointer flex-col gap-2 rounded-xl border-2 p-2 text-sm has-[:checked]:border-brand-600 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-500 border-gray-200">
                        <input type="radio" name="accent" value="custom" class="sr-only" @checked($mine === 'custom')>
                        <span class="flex h-14 items-center justify-center rounded-lg border border-dashed border-gray-300"><input type="color" name="custom_color" value="{{ old('custom_color', $user->preferences?->custom_color ?: '#4f46e5') }}" aria-label="Your own colour" class="h-10 w-14 cursor-pointer rounded border-0 bg-transparent" data-own></span>
                        <span class="break-words">Your own colour</span>
                    </label>
                </div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-600">Preview:</span>
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm" data-preview="mobile" aria-pressed="true">Mobile</button>
                    <button type="button" class="rounded-lg border border-gray-200 px-3 py-1.5 text-sm" data-preview="desktop">Desktop</button>
                </div>
                <iframe title="Theme preview (phone)" data-frame class="mx-auto mt-3 block h-[600px] w-[390px] max-w-full rounded-[26px] border-8 border-gray-900 bg-white" loading="lazy"
                        src="{{ route('dashboard') }}?accent={{ $mine }}"></iframe>
            </fieldset>
            <div>
                <label class="block text-sm font-medium text-gray-700">Language</label>
                <input type="text" name="language" value="{{ old('language', $user->preferences?->language) }}" maxlength="10"
                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700">Timezone</label>
                <select name="timezone" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                    @foreach ($timezones as $tz)
                        <option value="{{ $tz }}" @selected(old('timezone', $user->preferences?->timezone) === $tz)>{{ $tz }}</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="hidden" name="notification_sound" value="0">
                <input type="checkbox" name="notification_sound" value="1" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                       @checked(old('notification_sound', $user->preferences?->notification_sound))>
                Notification sound
            </label>
            <div class="sm:col-span-2">
                <p class="text-sm font-medium text-gray-700">Auto-download media</p>
                <div class="mt-2 flex flex-wrap gap-4">
                    @foreach (['auto_download_photos' => 'Photos', 'auto_download_videos' => 'Videos', 'auto_download_documents' => 'Documents'] as $field => $label)
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="{{ $field }}" value="0">
                            <input type="checkbox" name="{{ $field }}" value="1" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                   @checked(old($field, $user->preferences?->$field))>
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
                    Save preferences
                </button>
            </div>
        </form>
    </section>
    <div data-overlay hidden class="fixed inset-0 z-50 flex flex-col gap-2 bg-white p-3 sm:p-4" role="dialog" aria-modal="true" aria-label="Theme preview (desktop)">
        <div class="flex items-center justify-between gap-2"><strong>Theme preview: desktop</strong><button type="button" data-close class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white">Close</button></div>
        <iframe title="Theme preview (desktop)" data-frame-desk class="w-full flex-1 rounded-xl border border-gray-200"></iframe>
    </div>
    <script>
    (function () {
        var box = document.querySelector('[data-accents]'); if (!box) return;
        var frame = box.querySelector('[data-frame]'), over = document.querySelector('[data-overlay]'), desk = over.querySelector('[data-frame-desk]');
        var base = @json(route('dashboard')) + '?accent=';
        var own = box.querySelector('[data-own]');
        var pick = function () { var a = (box.querySelector('input[name=accent]:checked') || {}).value || 'indigo'; return a === 'custom' ? 'custom&color=' + encodeURIComponent(own.value) : a; };
        own.addEventListener('input', function () { box.querySelector('input[value=custom]').checked = true; frame.src = base + pick(); });
        box.addEventListener('change', function () { var a = pick(); if (a.indexOf('custom') !== 0) document.documentElement.dataset.accent = a; frame.src = base + a; });
        box.querySelector('[data-preview=desktop]').addEventListener('click', function () { desk.src = base + pick(); over.hidden = false; over.querySelector('[data-close]').focus(); });
        var close = function () { over.hidden = true; desk.src = 'about:blank'; };
        over.querySelector('[data-close]').addEventListener('click', close);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !over.hidden) close(); });
    })();
    </script>
</x-layouts.app>
