<?php

namespace Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Meetings\Http\Requests\RescheduleCalendarEventRequest;
use Modules\Meetings\Http\Requests\StoreCalendarEventRequest;
use Modules\Meetings\Http\Resources\CalendarEventResource;
use Modules\Meetings\Models\CalendarEvent;
use Modules\Meetings\Services\CalendarService;
use Modules\Meetings\Services\IcsGenerator;

class CalendarController extends Controller
{
    public function __construct(
        private readonly CalendarService $calendar,
    ) {
    }

    /**
     * Real month/week/day + past/future browsing needs an arbitrary
     * date window (eventsInRange) - but the no-params default must
     * keep behaving exactly like before (upcomingEvents), since
     * existing callers/tests rely on that shape.
     */
    public function index(Request $request): JsonResponse
    {
        $events = $request->filled('start') && $request->filled('end')
            ? $this->calendar->eventsInRange($request->user(), \Carbon\Carbon::parse($request->string('start')), \Carbon\Carbon::parse($request->string('end')))
            : $this->calendar->upcomingEvents($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => CalendarEventResource::collection($events->load(['rsvps', 'meeting'])),
            'errors' => null,
        ]);
    }

    /**
     * A personal/reminder/holiday/task entry with no linked meeting.
     */
    public function store(StoreCalendarEventRequest $request): JsonResponse
    {
        $event = $this->calendar->createStandaloneEvent($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Event created.',
            'data' => new CalendarEventResource($event),
            'errors' => null,
        ], 201);
    }

    public function destroy(Request $request, CalendarEvent $event): JsonResponse
    {
        $this->calendar->deleteStandaloneEvent($event, $request->user());

        return response()->json(['success' => true, 'message' => 'Event deleted.', 'data' => null, 'errors' => null]);
    }

    /**
     * Drag-to-reschedule (also used for drag-to-resize - only the
     * duration changes, start_at stays the same value it already was).
     */
    public function reschedule(RescheduleCalendarEventRequest $request, CalendarEvent $event): JsonResponse
    {
        $event = $this->calendar->reschedule(
            $event,
            $request->user(),
            \Carbon\Carbon::parse($request->validated('start_at')),
            \Carbon\Carbon::parse($request->validated('end_at')),
        );

        return response()->json([
            'success' => true,
            'message' => 'Event rescheduled.',
            'data' => new CalendarEventResource($event),
            'errors' => null,
        ]);
    }

    public function rsvp(Request $request, CalendarEvent $event): JsonResponse
    {
        $request->validate(['status' => ['required', 'in:accepted,declined,tentative']]);
        $this->calendar->rsvp($event, $request->user(), $request->input('status'));

        return response()->json(['success' => true, 'message' => 'RSVP recorded.', 'data' => null, 'errors' => null]);
    }

    /**
     * RAS-5 - GET /calendar/events/{eventId}/ics (FR-167)
     */
    public function ics(Request $request, CalendarEvent $event): Response
    {
        $organizer = $event->creator;
        $joinUrl = $event->meeting_id ? route('meetings.show', $event->meeting_id) : route('dashboard');

        $ics = IcsGenerator::forMeeting(
            $event->uuid,
            $event->title,
            $event->description,
            $event->start_at,
            $event->end_at,
            $organizer->email,
            $organizer->full_name,
            $joinUrl,
            $request->user()->email,
        );

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="invite.ics"',
        ]);
    }
}
