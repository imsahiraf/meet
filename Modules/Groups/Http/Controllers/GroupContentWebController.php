<?php

namespace Modules\Groups\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Groups\Exceptions\PollClosedException;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupAnnouncement;
use Modules\Groups\Models\GroupEvent;
use Modules\Groups\Models\GroupPoll;
use Modules\Groups\Services\GroupContentService;

class GroupContentWebController extends Controller
{
    public function __construct(
        private readonly GroupContentService $content,
    ) {
    }

    public function storeAnnouncement(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('manageAnnouncements', $group);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'announcement' => ['required', 'string', 'max:5000'],
            'is_pinned' => ['sometimes', 'boolean'],
        ]);

        $this->content->createAnnouncement($group, $request->user(), $data['title'], $data['announcement'], $request->boolean('is_pinned'), null);

        return back()->with('status', 'Announcement published.');
    }

    public function destroyAnnouncement(Group $group, GroupAnnouncement $announcement): RedirectResponse
    {
        $this->authorize('manageAnnouncements', $group);
        abort_unless($announcement->group_id === $group->id, 404);

        $this->content->deleteAnnouncement($announcement);

        return back()->with('status', 'Announcement deleted.');
    }

    public function storePoll(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('managePolls', $group);

        $data = $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'options' => ['required', 'array', 'min:2'],
            'options.*' => ['required', 'string', 'max:150'],
            'allow_multiple_votes' => ['sometimes', 'boolean'],
        ]);

        try {
            $this->content->createPoll($group, $request->user(), $data['question'], $data['options'], $request->boolean('allow_multiple_votes'), null);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['poll' => $e->getMessage()]);
        }

        return back()->with('status', 'Poll created.');
    }

    public function vote(Request $request, Group $group, GroupPoll $poll): RedirectResponse
    {
        $this->authorize('view', $group);
        abort_unless($poll->group_id === $group->id, 404);

        $data = $request->validate([
            'option_ids' => ['required', 'array', 'min:1'],
            'option_ids.*' => ['integer'],
        ]);

        try {
            $this->content->vote($poll, $request->user(), $data['option_ids']);
        } catch (PollClosedException $e) {
            return back()->withErrors(['poll' => $e->getMessage()]);
        }

        return back()->with('status', 'Vote recorded.');
    }

    public function closePoll(Group $group, GroupPoll $poll): RedirectResponse
    {
        $this->authorize('managePolls', $group);
        abort_unless($poll->group_id === $group->id, 404);

        $this->content->closePoll($poll);

        return back()->with('status', 'Poll closed.');
    }

    public function storeEvent(Request $request, Group $group): RedirectResponse
    {
        $this->authorize('manageEvents', $group);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'event_start' => ['required', 'date'],
            'event_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:event_start'],
        ]);

        $this->content->createEvent($group, $request->user(), $data['title'], $data['description'] ?? null, $data['event_start'], $data['event_end'] ?? null);

        return back()->with('status', 'Event created.');
    }

    public function destroyEvent(Group $group, GroupEvent $event): RedirectResponse
    {
        $this->authorize('manageEvents', $group);
        abort_unless($event->group_id === $group->id, 404);

        $this->content->deleteEvent($event);

        return back()->with('status', 'Event deleted.');
    }
}
