<x-layouts.app :title="$group->name . ' - Meet'">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-center gap-4">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xl font-semibold text-emerald-700">
                {{ strtoupper(substr($group->name, 0, 1)) }}
            </span>
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">{{ $group->name }}</h1>
                <p class="text-sm text-gray-500">{{ $group->activeMembers->count() }} members &middot; owned by {{ $group->owner->full_name }}</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('chat.show', $group->conversation) }}" class="rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-brand-700">
                Open chat
            </a>
            <form method="POST" action="{{ route('groups.mute', $group) }}">
                @csrf
                <input type="hidden" name="muted" value="{{ $membership->is_muted ? '0' : '1' }}">
                <button type="submit" class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-gray-50">
                    {{ $membership->is_muted ? 'Unmute' : 'Mute' }}
                </button>
            </form>
            <form method="POST" action="{{ route('groups.leave', $group) }}" data-confirm-message="Leave this group?" data-confirm-danger="1">
                @csrf
                <button type="submit" class="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium text-gray-600 transition hover:bg-red-50 hover:text-red-600">
                    Leave group
                </button>
            </form>
            @if ($can['delete'])
                <form method="POST" action="{{ route('groups.destroy', $group) }}" data-confirm-message="Delete this group permanently?" data-confirm-danger="1">
                    @csrf @method('DELETE')
                    <button type="submit" class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50">
                        Delete group
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Group info --}}
            <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-900">Group info</h2>
                @if ($can['update'])
                    <form method="POST" action="{{ route('groups.info.update', $group) }}" class="space-y-3">
                        @csrf @method('PATCH')
                        <input name="name" value="{{ $group->name }}" maxlength="150" required>
                        <textarea name="description" rows="2" maxlength="2000" placeholder="Description">{{ $group->description }}</textarea>
                        <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">Save</button>
                    </form>
                @else
                    <p class="text-sm text-gray-600">{{ $group->description ?: 'No description yet.' }}</p>
                @endif
            </section>

            {{-- Members --}}
            <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-900">Members ({{ $group->activeMembers->count() }})</h2>

                @if ($can['manageMembers'])
                    <form method="GET" action="{{ route('groups.show', $group) }}" class="relative mb-4">
                        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search contacts to add&hellip;">
                    </form>

                    @if (request('q'))
                        <div class="mb-4 divide-y divide-gray-100 rounded-lg border border-gray-100">
                            @forelse ($searchResults as $result)
                                <form method="POST" action="{{ route('groups.members.add', $group) }}" class="flex items-center justify-between gap-3 px-3 py-2">
                                    @csrf
                                    <input type="hidden" name="user_ids[]" value="{{ $result->contact_user_id }}">
                                    <span class="text-sm text-gray-900">{{ $result->contactUser->full_name }}</span>
                                    <button type="submit" class="rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-700">Add</button>
                                </form>
                            @empty
                                <p class="px-3 py-2 text-sm text-gray-400">No matching contacts to add.</p>
                            @endforelse
                        </div>
                    @endif
                @endif

                <ul class="divide-y divide-gray-100">
                    @foreach ($group->activeMembers as $member)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700">
                                    {{ strtoupper(substr($member->user->full_name, 0, 1)) }}
                                </span>
                                <span class="truncate text-sm text-gray-900">{{ $member->user->full_name }}</span>
                                <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">{{ $member->role->role_name }}</span>
                            </div>

                            @if ($can['manageRoles'] && $member->role->role_name !== 'Owner')
                                <div class="flex shrink-0 items-center gap-2">
                                    <form method="POST" action="{{ route('groups.assign-role', $group) }}">
                                        @csrf
                                        <input type="hidden" name="member_id" value="{{ $member->id }}">
                                        <select name="role_id" onchange="this.form.submit()" class="!w-auto text-xs">
                                            @foreach ($group->roles->where('role_name', '!=', 'Owner') as $role)
                                                <option value="{{ $role->id }}" @selected($role->id === $member->role_id)>{{ $role->role_name }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                    <form method="POST" action="{{ route('groups.transfer-ownership', $group) }}" data-confirm-message="Make {{ $member->user->full_name }} the new owner?">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $member->user_id }}">
                                        <button type="submit" class="text-xs font-medium text-brand-600 hover:text-brand-700">Make owner</button>
                                    </form>
                                    <form method="POST" action="{{ route('groups.members.remove', [$group, $member->user_id]) }}" data-confirm-message="Remove this member?" data-confirm-danger="1">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs font-medium text-gray-400 hover:text-red-600">Remove</button>
                                    </form>
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>

            {{-- Announcements --}}
            <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-900">Announcements</h2>

                @if ($can['manageAnnouncements'])
                    <form method="POST" action="{{ route('groups.announcements.store', $group) }}" class="mb-4 space-y-2">
                        @csrf
                        <input name="title" maxlength="150" placeholder="Title" required>
                        <textarea name="announcement" rows="2" maxlength="5000" placeholder="Announcement" required></textarea>
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="is_pinned" value="1" class="h-4 w-4 rounded border-gray-300 text-brand-600">
                            Pin to top
                        </label>
                        <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">Publish</button>
                    </form>
                @endif

                <ul class="space-y-3">
                    @forelse ($announcements as $announcement)
                        <li class="rounded-lg border border-gray-100 p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <p class="text-sm font-medium text-gray-900">
                                        {{ $announcement->title }}
                                        @if ($announcement->is_pinned)<span class="ml-1 text-amber-500">&#128204;</span>@endif
                                    </p>
                                    <p class="mt-1 text-sm text-gray-600">{{ $announcement->announcement }}</p>
                                    <p class="mt-1 text-xs text-gray-400">{{ $announcement->creator?->full_name }} &middot; {{ $announcement->created_at->diffForHumans() }}</p>
                                </div>
                                @if ($can['manageAnnouncements'])
                                    <form method="POST" action="{{ route('groups.announcements.destroy', [$group, $announcement]) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-gray-400 hover:text-red-600">Delete</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @empty
                        <p class="text-sm text-gray-400">No announcements yet.</p>
                    @endforelse
                </ul>
            </section>

            {{-- Polls --}}
            <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-900">Polls</h2>

                @if ($can['managePolls'])
                    <form method="POST" action="{{ route('groups.polls.store', $group) }}" class="mb-4 space-y-2">
                        @csrf
                        <input name="question" maxlength="255" placeholder="Ask a question" required>
                        <input name="options[]" maxlength="150" placeholder="Option 1" required>
                        <input name="options[]" maxlength="150" placeholder="Option 2" required>
                        <input name="options[]" maxlength="150" placeholder="Option 3 (optional)">
                        <label class="flex items-center gap-2 text-sm text-gray-600">
                            <input type="checkbox" name="allow_multiple_votes" value="1" class="h-4 w-4 rounded border-gray-300 text-brand-600">
                            Allow selecting multiple options
                        </label>
                        <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">Create poll</button>
                    </form>
                @endif

                <ul class="space-y-4">
                    @forelse ($polls as $poll)
                        @php $totalVotes = $poll->options->sum(fn ($o) => $o->votes->count()); @endphp
                        <li class="rounded-lg border border-gray-100 p-3">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm font-medium text-gray-900">{{ $poll->question }}</p>
                                @if ($poll->isClosed())
                                    <span class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">Closed</span>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('groups.polls.vote', [$group, $poll]) }}" class="mt-2 space-y-1.5">
                                @csrf
                                @foreach ($poll->options as $option)
                                    @php $voteCount = $option->votes->count(); @endphp
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="{{ $poll->allow_multiple_votes ? 'checkbox' : 'radio' }}" name="option_ids[]" value="{{ $option->id }}"
                                               class="h-4 w-4 border-gray-300 text-brand-600" {{ $poll->isClosed() ? 'disabled' : '' }}
                                               @checked($option->votes->contains('user_id', auth()->id()))>
                                        <span class="flex-1">{{ $option->option_text }}</span>
                                        <span class="text-xs text-gray-400">{{ $voteCount }} {{ $totalVotes ? '('.round($voteCount / max($totalVotes, 1) * 100).'%)' : '' }}</span>
                                    </label>
                                @endforeach
                                @unless ($poll->isClosed())
                                    <button type="submit" class="mt-1 rounded-lg bg-brand-600 px-3 py-1 text-xs font-medium text-white hover:bg-brand-700">Vote</button>
                                @endunless
                            </form>

                            @if ($can['managePolls'] && ! $poll->isClosed())
                                <form method="POST" action="{{ route('groups.polls.close', [$group, $poll]) }}" class="mt-2">
                                    @csrf
                                    <button type="submit" class="text-xs font-medium text-gray-500 hover:text-red-600">Close poll</button>
                                </form>
                            @endif
                        </li>
                    @empty
                        <p class="text-sm text-gray-400">No polls yet.</p>
                    @endforelse
                </ul>
            </section>

            {{-- Events --}}
            <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-sm font-semibold text-gray-900">Events</h2>

                @if ($can['manageEvents'])
                    <form method="POST" action="{{ route('groups.events.store', $group) }}" class="mb-4 space-y-2">
                        @csrf
                        <input name="title" maxlength="150" placeholder="Event title" required>
                        <textarea name="description" rows="2" maxlength="2000" placeholder="Description (optional)"></textarea>
                        <div class="grid grid-cols-2 gap-2">
                            <input type="datetime-local" name="event_start" required>
                            <input type="datetime-local" name="event_end">
                        </div>
                        <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">Create event</button>
                    </form>
                @endif

                <ul class="space-y-2">
                    @forelse ($events as $event)
                        <li class="flex items-start justify-between gap-2 rounded-lg border border-gray-100 p-3">
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ $event->title }}</p>
                                <p class="text-xs text-gray-500">{{ $event->event_start->format('M j, Y g:i A') }}@if ($event->event_end) &ndash; {{ $event->event_end->format('g:i A') }}@endif</p>
                                @if ($event->description)<p class="mt-1 text-sm text-gray-600">{{ $event->description }}</p>@endif
                            </div>
                            @if ($can['manageEvents'])
                                <form method="POST" action="{{ route('groups.events.destroy', [$group, $event]) }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="shrink-0 text-xs text-gray-400 hover:text-red-600">Delete</button>
                                </form>
                            @endif
                        </li>
                    @empty
                        <p class="text-sm text-gray-400">No upcoming events.</p>
                    @endforelse
                </ul>
            </section>
        </div>

        <div class="space-y-6">
            {{-- Invite --}}
            @if ($can['manageInvites'])
                <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Invite link</h2>
                    @if ($pendingInvite)
                        <p class="mb-2 break-all rounded-lg bg-gray-50 px-3 py-2 text-xs text-gray-600">
                            {{ url('/groups/join/'.$pendingInvite->invite_token) }}
                        </p>
                        <p class="mb-3 text-xs text-gray-400">Expires {{ $pendingInvite->expires_at?->diffForHumans() }}</p>
                        <form method="POST" action="{{ route('groups.invite.revoke', $group) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs font-medium text-red-600 hover:underline">Revoke link</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('groups.invite.generate', $group) }}">
                            @csrf
                            <button type="submit" class="w-full rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                                Create invite link
                            </button>
                        </form>
                    @endif
                </section>
            @endif

            {{-- Join requests --}}
            @if ($can['manageJoinRequests'] && $group->approval_required)
                <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Join requests ({{ $joinRequests->count() }})</h2>
                    <ul class="space-y-2">
                        @forelse ($joinRequests as $joinRequest)
                            <li class="rounded-lg border border-gray-100 p-3">
                                <p class="text-sm font-medium text-gray-900">{{ $joinRequest->user->full_name }}</p>
                                @if ($joinRequest->message)<p class="mt-1 text-xs text-gray-500">&ldquo;{{ $joinRequest->message }}&rdquo;</p>@endif
                                <div class="mt-2 flex gap-2">
                                    <form method="POST" action="{{ route('groups.join-request.approve', [$group, $joinRequest]) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg bg-brand-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-700">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('groups.join-request.reject', [$group, $joinRequest]) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-50">Reject</button>
                                    </form>
                                </div>
                            </li>
                        @empty
                            <p class="text-sm text-gray-400">No pending requests.</p>
                        @endforelse
                    </ul>
                </section>
            @endif

            {{-- Settings --}}
            @if ($can['manageSettings'])
                <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Settings</h2>
                    <form method="POST" action="{{ route('groups.settings.update', $group) }}" class="space-y-3">
                        @csrf @method('PATCH')
                        <label class="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="approval_required" value="1" class="h-4 w-4 rounded border-gray-300 text-brand-600" @checked($group->approval_required)>
                            Require approval to join
                        </label>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-gray-500">Member limit</label>
                            <input type="number" name="member_limit" min="2" max="100000" value="{{ $group->member_limit }}">
                        </div>
                        <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">Save settings</button>
                    </form>

                    <a href="{{ url('/api/v1/groups/'.$group->id.'/export') }}" class="mt-3 inline-block text-xs font-medium text-brand-600 hover:underline">
                        Export group info (CSV)
                    </a>
                </section>
            @endif

            {{-- Permissions --}}
            @if ($can['managePermissions'])
                <section class="rounded-2xl border border-gray-100 bg-white p-6 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900">Permissions</h2>
                    <form method="POST" action="{{ route('groups.permissions.update', $group) }}" class="space-y-2">
                        @csrf @method('PATCH')
                        @foreach ([
                            'admin_only_messaging' => 'Only admins can send messages',
                            'admin_only_edit_info' => 'Only admins can edit group info',
                            'admin_only_invite' => 'Only admins can invite members',
                            'admin_only_pin' => 'Only admins can pin messages',
                            'allow_member_reactions' => 'Members can react to messages',
                            'allow_member_file_sharing' => 'Members can share files',
                        ] as $key => $label)
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="{{ $key }}" value="1" class="h-4 w-4 rounded border-gray-300 text-brand-600" @checked($group->{$key})>
                                {{ $label }}
                            </label>
                        @endforeach
                        <button type="submit" class="rounded-lg bg-brand-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-brand-700">Save permissions</button>
                    </form>
                </section>
            @endif
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
