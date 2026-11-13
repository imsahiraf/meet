<?php

namespace Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Admin\Services\SettingsService;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingGuestSession;
use Modules\Meetings\Services\CalendarService;
use Modules\Meetings\Services\MeetingService;

class MeetingWebController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetings,
        private readonly CalendarService $calendar,
        private readonly SettingsService $settings,
    ) {
    }

    public function index(Request $request): View
    {
        return $this->meetingsPage($request, 'all');
    }

    public function history(Request $request): View
    {
        return $this->meetingsPage($request, 'history');
    }

    /**
     * Calendar is now its own nav-level destination (a dedicated icon,
     * not a tab buried inside Meetings) with real month/week/day views
     * and drag-to-reschedule - enough chrome that it gets its own full
     * page/view rather than squeezing into the shared All/History tabs
     * template below.
     */
    public function calendar(): View
    {
        return view('meetings::calendar');
    }

    /**
     * All/History used to be two separate pages, only cross-linked via
     * plain header links - now one page with client-side tabs
     * (mirroring Chat's All/Chats/Groups pattern). Both route names
     * are kept (a bookmarked/emailed link to either should keep
     * working) and just render the same view pre-selected to a
     * different tab.
     */
    private function meetingsPage(Request $request, string $activeTab): View
    {
        return view('meetings::index', [
            'upcoming' => $this->meetings->upcoming($request->user()),
            'history' => $this->meetings->history($request->user()),
            'activeTab' => $activeTab,
        ]);
    }

    public function createForm(): View
    {
        return view('meetings::schedule', [
            'defaultWaitingRoom' => $this->settings->get('meetings.default_waiting_room_enabled', true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'scheduled_start' => ['required', 'date', 'after:now'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
            'timezone' => ['required', 'timezone:all_with_bc'],
            'password' => ['sometimes', 'nullable', 'string', 'max:64'],
            'guest_enabled' => ['sometimes', 'boolean'],
            'waiting_room_enabled' => ['sometimes', 'boolean'],
            'participant_emails' => ['sometimes', 'nullable', 'string'],
        ]);

        $emails = ! empty($data['participant_emails']) ? explode(',', $data['participant_emails']) : [];
        [$participantIds, $adHocEmails] = $this->calendar->resolveInviteeEmails($emails);

        $meeting = $this->meetings->createScheduled($request->user(), $data);
        $this->calendar->scheduleMeeting($meeting, $request->user(), $participantIds, $adHocEmails);

        return redirect()->route('meetings.index')->with('status', 'Meeting scheduled and invitations sent.');
    }

    public function instant(Request $request): RedirectResponse
    {
        $meeting = $this->meetings->createInstant($request->user(), $request->input('title') ?: 'Instant Meeting');

        return redirect()->route('meetings.show', $meeting);
    }

    public function show(Request $request, Meeting $meeting): View
    {
        return view('meetings::room', [
            'meeting' => $meeting->load(['organizer', 'settings']),
            'isOrganizer' => $meeting->organizer_id === $request->user()->id,
            // Refreshing the tab while still an active (never-left)
            // participant should rejoin straight into the room instead
            // of re-showing the lobby/join screen from scratch.
            'alreadyJoined' => $this->meetings->activeParticipantFor($meeting, $request->user()) !== null,
        ]);
    }

    public function guestCreateForm(): View
    {
        return view('meetings::guest-create', [
            'enabled' => $this->settings->get('meetings.guest_creation_enabled', true),
        ]);
    }

    public function guestCreate(Request $request): RedirectResponse
    {
        abort_unless($this->settings->get('meetings.guest_creation_enabled', true), 403, 'Guest meeting creation is currently disabled.');

        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:120'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'password' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $result = $this->meetings->guestCreateInstant($data['guest_name'], $data['title'] ?? null, $data['password'] ?? null);

        return redirect()->route('meetings.guest-room', $result['guestSession']);
    }

    public function guestLanding(string $code): View
    {
        $meeting = Meeting::where('meeting_code', $code)->firstOrFail();

        return view('meetings::guest-landing', ['meeting' => $meeting]);
    }

    public function guestRoom(MeetingGuestSession $guestSession): View
    {
        return view('meetings::guest-room', [
            'guestSession' => $guestSession,
            'meeting' => $guestSession->meeting,
        ]);
    }
}
