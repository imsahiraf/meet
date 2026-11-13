<x-layouts.app :title="$community->name . ' - Meet'">
    <div class="mb-6 flex items-start justify-between gap-4">
        <div class="flex items-center gap-3">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-purple-100 text-lg font-semibold text-purple-700">
                {{ strtoupper(substr($community->name, 0, 1)) }}
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">{{ $community->name }}</h1>
                <p class="text-sm text-gray-500">{{ $community->groups->count() }} {{ $community->groups->count() === 1 ? 'group' : 'groups' }} &middot; owned by {{ $community->owner->full_name }}</p>
                @if ($community->description)
                    <p class="mt-1 text-sm text-gray-600">{{ $community->description }}</p>
                @endif
            </div>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <a href="{{ route('communities.index') }}" class="text-sm font-medium text-gray-500 hover:text-gray-700">&larr; Back</a>
            @if ($community->owner_id === auth()->id())
                <form method="POST" action="{{ route('communities.destroy', $community) }}"
                      data-confirm-message="Delete this community? Its groups are kept but no longer part of a community." data-confirm-danger="1">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-lg border border-red-200 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50">Delete community</button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Groups in this community</h2>
                <ul class="divide-y divide-gray-100">
                    @foreach ($community->groups as $group)
                        <li class="flex items-center justify-between gap-4 py-3">
                            <a href="{{ route('groups.show', $group) }}" class="flex min-w-0 items-center gap-3">
                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-semibold text-emerald-700">
                                    {{ strtoupper(substr($group->name, 0, 1)) }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-medium text-gray-900">
                                        {{ $group->name }}
                                        @if ($group->id === $community->announcement_group_id)
                                            <span class="ml-1 rounded bg-purple-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-purple-600">Announcements</span>
                                        @endif
                                    </span>
                                    <span class="block text-xs text-gray-400">{{ $group->active_members_count }} {{ $group->active_members_count === 1 ? 'member' : 'members' }}</span>
                                </span>
                            </a>

                            @if ($group->id !== $community->announcement_group_id && ($canManage || $group->owner_id === auth()->id()))
                                <form method="POST" action="{{ route('communities.groups.detach', [$community, $group]) }}"
                                      data-confirm-message="Remove this group from the community?" data-confirm-danger="1">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="shrink-0 text-sm font-medium text-red-500 hover:text-red-700">Remove</button>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>

                @if ($canManage)
                    <form method="POST" action="{{ route('communities.groups.attach', $community) }}" class="mt-4 flex gap-2 border-t border-gray-100 pt-4">
                        @csrf
                        @if ($availableGroups->isEmpty())
                            <p class="text-sm text-gray-400">You don't own any other groups to add yet.</p>
                        @else
                            <select name="group_id" required class="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                                <option value="">Add an existing group you own&hellip;</option>
                                @foreach ($availableGroups as $group)
                                    <option value="{{ $group->id }}">{{ $group->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Add</button>
                        @endif
                    </form>
                @endif
            </section>

            <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Community announcements</h2>

                @if ($canManage)
                    <form method="POST" action="{{ route('communities.announcements.store', $community) }}" class="mb-4 space-y-2 border-b border-gray-100 pb-4">
                        @csrf
                        <input type="text" name="title" required maxlength="150" placeholder="Title"
                               class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm">
                        <textarea name="announcement" required rows="2" maxlength="5000" placeholder="Announcement"
                                  class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:text-sm"></textarea>
                        <div class="flex justify-end">
                            <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">Post</button>
                        </div>
                    </form>
                @endif

                <ul class="space-y-3">
                    @forelse ($community->announcementGroup->announcements as $announcement)
                        <li class="rounded-lg bg-gray-50 px-4 py-3">
                            <p class="text-sm font-medium text-gray-900">{{ $announcement->title }}</p>
                            <p class="mt-1 text-sm text-gray-600">{{ $announcement->announcement }}</p>
                            <p class="mt-1 text-xs text-gray-400">{{ $announcement->creator->full_name }} &middot; {{ $announcement->created_at->diffForHumans() }}</p>
                        </li>
                    @empty
                        <li class="py-6 text-center text-sm text-gray-400">No announcements yet.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>

    <script>
        document.addEventListener('submit', async function (event) {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.dataset.confirmMessage) {
                return;
            }

            event.preventDefault();

            const confirmed = await MeetDialog.confirm(form.dataset.confirmMessage, {
                danger: form.dataset.confirmDanger === '1',
            });

            if (confirmed) {
                form.submit();
            }
        });
    </script>
</x-layouts.app>
