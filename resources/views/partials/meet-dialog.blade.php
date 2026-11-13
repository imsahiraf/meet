{{-- Themed replacement for the browser's native alert()/confirm()/prompt() -
     those can't be styled and look jarring against the rest of the app.
     Defined as a plain classic script (not a Vite module) specifically so
     it's ready before any page-specific inline script further down the
     same document tries to call it - no async "wait for module" dance
     needed anywhere it's used. --}}
<div id="meet-dialog-overlay" class="fixed inset-0 z-[100] hidden items-center justify-center bg-black/50 p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
        <h3 id="meet-dialog-title" class="hidden text-base font-semibold text-gray-900"></h3>
        <p id="meet-dialog-message" class="mt-1 whitespace-pre-line text-sm text-gray-600"></p>
        <input id="meet-dialog-input" type="text" class="mt-3 hidden w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-1 focus:ring-brand-500">
        <div class="mt-5 flex items-center justify-end gap-2">
            <button type="button" id="meet-dialog-cancel" class="rounded-lg px-3 py-2 text-sm font-medium text-gray-500 hover:bg-gray-100">Cancel</button>
            <button type="button" id="meet-dialog-ok" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">OK</button>
        </div>
    </div>
</div>
<script>
(function () {
    var overlay = document.getElementById('meet-dialog-overlay');
    var titleEl = document.getElementById('meet-dialog-title');
    var messageEl = document.getElementById('meet-dialog-message');
    var inputEl = document.getElementById('meet-dialog-input');
    var cancelBtn = document.getElementById('meet-dialog-cancel');
    var okBtn = document.getElementById('meet-dialog-ok');

    var queue = [];
    var current = null;

    function open(opts) {
        return new Promise(function (resolve) {
            queue.push({ opts: opts, resolve: resolve });
            if (!current) processNext();
        });
    }

    function processNext() {
        current = queue.shift();
        if (!current) return;
        var opts = current.opts;

        titleEl.textContent = opts.title || '';
        titleEl.classList.toggle('hidden', !opts.title);
        messageEl.textContent = opts.message || '';

        okBtn.textContent = opts.okText || 'OK';
        okBtn.className = 'rounded-lg px-4 py-2 text-sm font-semibold text-white ' +
            (opts.danger ? 'bg-red-600 hover:bg-red-700' : 'bg-brand-600 hover:bg-brand-700');

        cancelBtn.classList.toggle('hidden', opts.type === 'alert');
        cancelBtn.textContent = opts.cancelText || 'Cancel';

        inputEl.classList.toggle('hidden', opts.type !== 'prompt');
        if (opts.type === 'prompt') {
            inputEl.value = opts.defaultValue || '';
            inputEl.placeholder = opts.placeholder || '';
        }

        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
        setTimeout(function () {
            (opts.type === 'prompt' ? inputEl : okBtn).focus();
        }, 0);
    }

    function close(result) {
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
        var finishing = current;
        current = null;
        if (finishing) finishing.resolve(result);
        if (queue.length) setTimeout(processNext, 150);
    }

    okBtn.addEventListener('click', function () {
        if (!current) return;
        var opts = current.opts;
        if (opts.type === 'prompt') return close(inputEl.value);
        return close(true);
    });

    cancelBtn.addEventListener('click', function () {
        if (!current) return;
        var opts = current.opts;
        close(opts.type === 'prompt' ? null : false);
    });

    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); okBtn.click(); }
        if (e.key === 'Escape') { e.preventDefault(); cancelBtn.click(); }
    });

    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) cancelBtn.click();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && current && !overlay.classList.contains('hidden')) cancelBtn.click();
    });

    /**
     * Drop-in themed replacement for the browser's native dialogs, all
     * Promise-based (native confirm()/prompt() are synchronous, which a
     * styled modal can never be):
     *   MeetDialog.alert(message, {title, okText}) -> Promise<void>
     *   MeetDialog.confirm(message, {title, okText, cancelText, danger}) -> Promise<boolean>
     *   MeetDialog.prompt(message, {title, defaultValue, placeholder, okText, cancelText}) -> Promise<string|null>
     */
    window.MeetDialog = {
        alert: function (message, opts) {
            opts = opts || {};
            return open({ type: 'alert', message: message, title: opts.title, okText: opts.okText });
        },
        confirm: function (message, opts) {
            opts = opts || {};
            return open({
                type: 'confirm',
                message: message,
                title: opts.title,
                okText: opts.okText,
                cancelText: opts.cancelText,
                danger: opts.danger,
            });
        },
        prompt: function (message, opts) {
            opts = opts || {};
            return open({
                type: 'prompt',
                message: message,
                title: opts.title,
                defaultValue: opts.defaultValue,
                placeholder: opts.placeholder,
                okText: opts.okText,
                cancelText: opts.cancelText,
            });
        },
    };
})();
</script>
