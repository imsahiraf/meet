<x-layouts.app title="Connected accounts - Meet">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Connected accounts</h1>
            <p class="mt-1 text-sm text-gray-500">Accounts you can sign in with instead of a password.</p>
        </div>
        <a href="{{ route('profile.security') }}" class="text-sm font-medium text-brand-600 hover:text-brand-700">&larr; Back to Account &amp; Security</a>
    </div>

    <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
        @if ($errors->has('oauth'))
            <p class="mb-3 text-sm text-red-600">{{ $errors->first('oauth') }}</p>
        @endif

        @if ($oauthIdentities->isNotEmpty())
            <ul class="divide-y divide-gray-100 rounded-lg border border-gray-200">
                @foreach ($oauthIdentities as $identity)
                    <li class="flex items-center justify-between gap-3 px-4 py-3">
                        <p class="text-sm font-medium capitalize text-gray-900">{{ $identity->provider }}</p>
                        <form method="POST" action="{{ route('profile.oauth.unlink', $identity) }}" class="js-confirm-unlink-form">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="text-sm text-gray-400">No connected accounts yet - sign in with Google from the login page to connect one.</p>
        @endif
    </section>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.js-confirm-unlink-form').forEach(function (form) {
                form.addEventListener('submit', async function (e) {
                    e.preventDefault();
                    if (!(await MeetDialog.confirm('Remove this connected account?', {danger: true}))) {
                        return;
                    }
                    form.submit();
                });
            });
        });
    </script>
</x-layouts.app>
