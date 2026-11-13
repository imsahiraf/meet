<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Contacts\Services\ContactService;
use Modules\Groups\Exceptions\CannotRemoveOwnerException;
use Modules\Groups\Exceptions\DuplicateGroupMemberException;
use Modules\Groups\Exceptions\DuplicateJoinRequestException;
use Modules\Groups\Exceptions\GroupMemberLimitReachedException;
use Modules\Groups\Exceptions\InvalidGroupInviteException;
use Modules\Groups\Exceptions\OwnershipTransferRequiredException;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupInvite;
use Modules\Groups\Models\GroupJoinRequest;
use Modules\Groups\Services\GroupInviteService;
use Modules\Groups\Services\GroupService;

class GroupsWebController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
        private readonly GroupInviteService $invites,
        private readonly ContactService $contacts,
    ) {
    }

    public function index(Request $request): View
    {
        return view('groups::index', [
            'groups' => $this->groups->listForUser($request->user(), $request->string('q')->trim()->toString() ?: null),
        ]);
    }

    public function create(Request $request): View
    {
        return view('groups::create', [
            'contacts' => $this->contacts->listContacts($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'group_name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'members' => ['required', 'array', 'min:1'],
            'members.*' => ['integer', 'exists:users,id'],
        ]);

        $group = $this->groups->create(
            $request->user(),
            $data['group_name'],
            $data['description'] ?? null,
            null,
            $data['members'],
        );

        return redirect()->route('groups.show', $group)->with('status', 'Group created.');
    }

    public function show(Request $request, Group $group): View
    {
        $this->authorize('view', $group);

        $group->load(['owner', 'roles.permissions', 'activeMembers.user', 'activeMembers.role']);
        $membership = $group->members->firstWhere('user_id', $request->user()->id);

        return view('groups::show', [
            'group' => $group,
            'membership' => $membership,
            'can' => [
                'update' => $request->user()->can('update', $group),
                'delete' => $request->user()->can('delete', $group),
                'manageMembers' => $request->user()->can('manageMembers', $group),
                'manageRoles' => $request->user()->can('manageRoles', $group),
                'managePermissions' => $request->user()->can('managePermissions', $group),
                'manageSettings' => $request->user()->can('manageSettings', $group),
                'manageInvites' => $request->user()->can('manageInvites', $group),
                'manageJoinRequests' => $request->user()->can('manageJoinRequests', $group),
                'manageAnnouncements' => $request->user()->can('manageAnnouncements', $group),
                'managePolls' => $request->user()->can('managePolls', $group),
                'manageEvents' => $request->user()->can('manageEvents', $group),
            ],
            'searchResults' => $request->string('q')->trim()->toString()
                ? $this->contacts->listContacts($request->user())->filter(
                    fn ($c) => str_contains(strtolower($c->contactUser->full_name), strtolower($request->string('q')->trim()->toString()))
                        && ! $group->activeMembers->contains('user_id', $c->contact_user_id)
                )
                : collect(),
            'pendingInvite' => GroupInvite::where('group_id', $group->id)->where('status', 'pending')->latest()->first(),
            'joinRequests' => $group->approval_required ? $group->joinRequests()->with('user')->where('status', 'pending')->latest()->get() : collect(),
            'announcements' => $group->announcements()->with('creator')->orderByDesc('is_pinned')->latest()->get(),
            'polls' => $group->polls()->with(['options.votes', 'creator'])->latest()->get(),
            'events' => $group->events()->orderBy('event_start')->get(),
        ]);
    }

    public function destroy(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('delete', $group);

        $this->groups->delete($group, $request->user());

        return redirect()->route('groups.index')->with('status', 'Group deleted.');
    }

    public function leave(Request $request, Group $group): RedirectResponse
    {
        try {
            $this->groups->leave($group, $request->user());
        } catch (OwnershipTransferRequiredException $e) {
            return back()->withErrors(['group' => $e->getMessage()]);
        }

        return redirect()->route('groups.index')->with('status', 'You left the group.');
    }

    public function updateInfo(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('update', $group);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $this->groups->update($group, $request->user(), $data);

        return back()->with('status', 'Group info updated.');
    }

    public function addMembers(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('manageMembers', $group);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        try {
            $this->groups->addMembers($group, $request->user(), $data['user_ids']);
        } catch (DuplicateGroupMemberException|GroupMemberLimitReachedException $e) {
            return back()->withErrors(['members' => $e->getMessage()]);
        }

        return back()->with('status', 'Members added.');
    }

    public function removeMember(Request $request, Group $group, int $userId): RedirectResponse
    {
        $this->authorize('manageMembers', $group);

        try {
            $this->groups->removeMember($group, $request->user(), $userId);
        } catch (CannotRemoveOwnerException $e) {
            return back()->withErrors(['members' => $e->getMessage()]);
        }

        return back()->with('status', 'Member removed.');
    }

    public function transferOwnership(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('manageRoles', $group);

        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $this->groups->transferOwnership($group, $request->user(), (int) $data['user_id']);

        return back()->with('status', 'Ownership transferred.');
    }

    public function assignRole(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('manageRoles', $group);

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'role_id' => ['required', 'integer'],
        ]);

        try {
            $this->groups->assignRole($group, $request->user(), (int) $data['member_id'], (int) $data['role_id']);
        } catch (CannotRemoveOwnerException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('status', 'Role updated.');
    }

    public function updateSettings(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('manageSettings', $group);

        $data = $request->validate([
            'approval_required' => ['sometimes', 'boolean'],
            'member_limit' => ['sometimes', 'integer', 'min:2', 'max:100000'],
        ]);
        $data['approval_required'] = $request->boolean('approval_required');

        $this->groups->updateSettings($group, $request->user(), $data);

        return back()->with('status', 'Settings updated.');
    }

    public function updatePermissions(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('managePermissions', $group);

        $toggles = [
            'admin_only_messaging' => $request->boolean('admin_only_messaging'),
            'admin_only_edit_info' => $request->boolean('admin_only_edit_info'),
            'admin_only_invite' => $request->boolean('admin_only_invite'),
            'admin_only_pin' => $request->boolean('admin_only_pin'),
            'allow_member_reactions' => $request->boolean('allow_member_reactions'),
            'allow_member_file_sharing' => $request->boolean('allow_member_file_sharing'),
        ];

        $this->groups->updatePermissions($group, $request->user(), $toggles, $request->input('roles', []));

        return back()->with('status', 'Permissions updated.');
    }

    public function toggleMute(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('view', $group);

        $this->groups->toggleMute($group, $request->user(), $request->boolean('muted'));

        return back()->with('status', $request->boolean('muted') ? 'Group muted.' : 'Group unmuted.');
    }

    public function toggleArchive(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('view', $group);

        $this->groups->toggleArchive($group, $request->user(), $request->boolean('archived'));

        return back()->with('status', $request->boolean('archived') ? 'Group archived.' : 'Group unarchived.');
    }

    public function generateInvite(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('manageInvites', $group);

        $this->invites->generateInvite($group, $request->user(), null, 168);

        return back()->with('status', 'Invite link created.');
    }

    public function revokeInvite(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('manageInvites', $group);

        $this->invites->revokeInvite($group, $request->user());

        return back()->with('status', 'Invite link revoked.');
    }

    public function showJoinPage(Request $request, string $token): View|RedirectResponse
    {
        $invite = GroupInvite::where('invite_token', $token)->where('status', 'pending')->firstOrFail();

        return view('groups::join', ['invite' => $invite]);
    }

    public function joinViaInvite(Request $request, string $token): RedirectResponse
    {
        try {
            $group = $this->invites->joinViaInvite($request->user(), $token);
        } catch (InvalidGroupInviteException|DuplicateGroupMemberException|GroupMemberLimitReachedException $e) {
            return redirect()->route('groups.index')->withErrors(['invite' => $e->getMessage()]);
        }

        return redirect()->route('groups.show', $group)->with('status', 'You joined the group.');
    }

    public function submitJoinRequest(Request $request, Group $group): RedirectResponse
    {
        try {
            $this->invites->submitJoinRequest($group, $request->user(), $request->input('message'));
        } catch (DuplicateJoinRequestException|DuplicateGroupMemberException $e) {
            return back()->withErrors(['join' => $e->getMessage()]);
        }

        return back()->with('status', 'Join request submitted.');
    }

    public function approveJoinRequest(Request $request, Group $group, GroupJoinRequest $joinRequest): RedirectResponse
    {
        $this->authorize('manageJoinRequests', $group);

        try {
            $this->invites->approveJoinRequest($group, $request->user(), $joinRequest);
        } catch (GroupMemberLimitReachedException $e) {
            return back()->withErrors(['join' => $e->getMessage()]);
        }

        return back()->with('status', 'Join request approved.');
    }

    public function rejectJoinRequest(Request $request, Group $group, GroupJoinRequest $joinRequest): RedirectResponse
    {
        $this->authorize('manageJoinRequests', $group);

        $this->invites->rejectJoinRequest($group, $request->user(), $joinRequest);

        return back()->with('status', 'Join request rejected.');
    }
}
