<x-layouts.app title="Change password - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Change password</h1>
            <p class="mt-1 text-sm text-gray-500">Update the password you sign in with.</p>
        </div>
        <a href="{{ route('profile.security') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to Account &amp; Security</a>
    </div>

    <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <div id="password-change-status" class="hidden sm:col-span-2 mb-4 rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-700"></div>

        <form method="POST" action="{{ route('profile.password') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @csrf
            @method('PUT')

            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700">Current password</label>
                <input type="password" name="current_password" autocomplete="current-password"
                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">New password</label>
                <input type="password" name="new_password" autocomplete="new-password"
                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Confirm new password</label>
                <input type="password" name="new_password_confirmation" autocomplete="new-password"
                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-brand-700">
                    Change password
                </button>
            </div>
        </form>
    </section>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[action$="/profile/password"]');
        if (!form) return;
        var nativeSubmit = false;
        var statusEl = document.getElementById('password-change-status');

        form.addEventListener('submit', function (event) {
            if (nativeSubmit) return;
            event.preventDefault();

            axios.put(form.action, {
                current_password: form.querySelector('[name="current_password"]').value,
                new_password: form.querySelector('[name="new_password"]').value,
                new_password_confirmation: form.querySelector('[name="new_password_confirmation"]').value,
            }).then(function () {
                form.reset();
                statusEl.textContent = 'Password changed successfully.';
                statusEl.classList.remove('hidden');
            }).catch(function () {
                // Wrong current password (or any other server-side
                // rejection) - nothing was mutated by the failed AJAX
                // attempt, so a real native submission is safe and
                // reuses the existing session-flashed error display
                // instead of duplicating it here.
                nativeSubmit = true;
                form.submit();
            });
        });
    });
    </script>
</x-layouts.app>
