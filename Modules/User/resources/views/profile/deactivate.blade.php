<x-layouts.app title="Deactivate account - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Deactivate account</h1>
            <p class="mt-1 text-sm text-gray-500">This signs you out of every device. It's not a permanent delete - an admin can restore your account later.</p>
        </div>
        <a href="{{ route('profile.security') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to Account &amp; Security</a>
    </div>

    <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('profile.deactivate') }}" id="deactivate-account-form">
            @csrf
            @method('DELETE')

            <div class="max-w-sm">
                <label class="block text-sm font-medium text-gray-700">Confirm your password</label>
                <input type="password" name="password" autocomplete="current-password"
                       class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
            </div>
            <div class="mt-4">
                <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-red-700">
                    Deactivate my account
                </button>
            </div>
        </form>
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.getElementById('deactivate-account-form');
            form.addEventListener('submit', async function (e) {
                e.preventDefault();
                if (!(await MeetDialog.confirm('Deactivate your account? You will be signed out everywhere. An admin can restore it later.', {danger: true}))) {
                    return;
                }
                form.submit();
            });
        });
    </script>
</x-layouts.app>
