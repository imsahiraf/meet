<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Groups\Models\Community;
use Modules\Groups\Models\Group;
use Modules\Groups\Services\CommunityService;

class CommunityWebController extends Controller
{
    public function __construct(
        private readonly CommunityService $communities,
    ) {
    }

    public function index(Request $request): View
    {
        return view('groups::communities.index', [
            'communities' => $this->communities->listVisibleForUser($request->user()),
        ]);
    }

    public function create(): View
    {
        return view('groups::communities.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $community = $this->communities->create($request->user(), $data['name'], $data['description'] ?? null);

        return redirect()->route('communities.show', $community)->with('status', 'Community created.');
    }

    public function show(Request $request, Community $community): View
    {
        $community = $this->communities->show($request->user(), $community);

        return view('groups::communities.show', [
            'community' => $community,
            'availableGroups' => $this->communities->ownedGroupsAvailableToAttach($request->user()),
            'canManage' => $this->communities->isCommunityAdmin($request->user(), $community),
        ]);
    }

    public function attachGroup(Request $request, Community $community): RedirectResponse
    {
        $data = $request->validate(['group_id' => ['required', 'integer', 'exists:groups,id']]);

        $this->communities->attachGroup($request->user(), $community, Group::findOrFail($data['group_id']));

        return back()->with('status', 'Group added to the community.');
    }

    public function detachGroup(Request $request, Community $community, Group $group): RedirectResponse
    {
        $this->communities->detachGroup($request->user(), $community, $group);

        return back()->with('status', 'Group removed from the community.');
    }

    public function postAnnouncement(Request $request, Community $community): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'announcement' => ['required', 'string', 'max:5000'],
        ]);

        $this->communities->postAnnouncement($request->user(), $community, $data['title'], $data['announcement']);

        return back()->with('status', 'Announcement posted.');
    }

    public function destroy(Request $request, Community $community): RedirectResponse
    {
        $this->communities->deleteCommunity($request->user(), $community);

        return redirect()->route('communities.index')->with('status', 'Community deleted.');
    }
}
