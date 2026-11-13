<?php

namespace Modules\Meetings\Services;

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Models\User;
use Modules\Chat\Repositories\MessageRepository;
use Modules\Chat\Services\ConversationService;
use Modules\Core\Services\PushNotificationService;
use Modules\Meetings\Mail\MeetingInvitationMail;
use Modules\Meetings\Models\CalendarEvent;
use Modules\Meetings\Models\CalendarReminder;
use Modules\Meetings\Models\CalendarRsvp;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingInvitation;

class CalendarService
{
    public function __construct(
        private readonly PushNotificationService $push,
        private readonly ConversationService $conversations,
        private readonly MessageRepository $messages,
    ) {
    }

    /**
     * Splits a raw list of invitee email addresses into existing-user
     * ids (merged with any already-known ids) and genuinely ad-hoc
     * emails with no matching account - so a caller doesn't have to
     * duplicate the "is this actually one of our users" lookup itself.
     * Shared by both the web scheduling form and the API schedule()
     * endpoint, which previously only matched emails on the web side -
     * an API-scheduled meeting invited by email never got the invitee's
     * MeetingInvitation/CalendarRsvp/CalendarReminder rows even when
     * that email belonged to a real registered user.
     *
     * @return array{0: int[], 1: string[]} [$participantIds, $adHocEmails]
     */
    public function resolveInviteeEmails(array $emails, array $knownParticipantIds = []): array
    {
        $emails = array_values(array_filter(array_map('trim', $emails)));

        if (empty($emails)) {
            return [array_values(array_unique($knownParticipantIds)), []];
        }

        $matchedUsers = User::whereIn('email', $emails)->get(['id', 'email']);
        $participantIds = array_values(array_unique(array_merge($knownParticipantIds, $matchedUsers->pluck('id')->all())));
        $matchedEmails = $matchedUsers->pluck('email')->map(fn ($email) => strtolower($email))->all();
        $adHocEmails = array_values(array_filter($emails, fn ($email) => ! in_array(strtolower($email), $matchedEmails, true)));

        return [$participantIds, $adHocEmails];
    }

    /**
     * FR-124/FR-167/FR-168 - creates the calendar_event + one
     * invitation/RSVP/reminder per invited registered user, plus one
     * invited_email-only invitation per ad-hoc address that doesn't
     * match an existing account (no RSVP/reminder tracking for those -
     * there's no user row to hang it off). Each invitee gets their own
     * ICS with an ATTENDEE line naming them specifically, and the join
     * URL that actually applies to them: the authenticated meeting
     * page for a registered invitee, the public guest-landing link
     * for an ad-hoc one (they have no account to log into).
     */
    public function scheduleMeeting(Meeting $meeting, User $organizer, array $participantIds, array $adHocEmails = []): CalendarEvent
    {
        $event = CalendarEvent::create([
            'created_by' => $organizer->id,
            'meeting_id' => $meeting->id,
            'event_type' => 'meeting',
            'title' => $meeting->title,
            'description' => $meeting->description,
            'start_at' => $meeting->scheduled_start,
            'end_at' => $meeting->scheduled_end,
            'timezone' => $meeting->timezone,
            'status' => 'scheduled',
        ]);

        $reminderMinutes = (int) config('meetings.default_reminder_minutes', 15);
        $authenticatedJoinUrl = route('meetings.show', $meeting);
        $guestJoinUrl = route('meetings.guest-landing', $meeting->meeting_code);

        foreach (array_unique($participantIds) as $userId) {
            if ((int) $userId === $organizer->id) {
                continue;
            }

            $invitee = User::find($userId);

            if (! $invitee) {
                continue;
            }

            MeetingInvitation::create([
                'meeting_id' => $meeting->id,
                'invited_by' => $organizer->id,
                'invited_user_id' => $invitee->id,
                'invitation_status' => 'pending',
                'sent_at' => now(),
            ]);

            CalendarRsvp::create([
                'calendar_event_id' => $event->id,
                'user_id' => $invitee->id,
                'status' => 'pending',
            ]);

            CalendarReminder::create([
                'calendar_event_id' => $event->id,
                'user_id' => $invitee->id,
                'remind_before_minutes' => $reminderMinutes,
                'channel' => 'email',
            ]);

            $ics = IcsGenerator::forMeeting(
                $meeting->uuid,
                $meeting->title,
                $meeting->description,
                $meeting->scheduled_start,
                $meeting->scheduled_end,
                $organizer->email,
                $organizer->full_name,
                $authenticatedJoinUrl,
                $invitee->email,
            );

            // ->send(), not ->queue(): queuing this made delivery
            // silently depend on a queue worker being alive (see
            // RegistrationOtpNotification's docblock for the same
            // issue found and fixed for OTP/password-reset emails).
            Mail::to($invitee->email)->send(new MeetingInvitationMail($meeting, $organizer->full_name, $authenticatedJoinUrl, $ics));
        }

        foreach (array_unique(array_map('strtolower', $adHocEmails)) as $email) {
            if ($email === strtolower($organizer->email)) {
                continue;
            }

            MeetingInvitation::create([
                'meeting_id' => $meeting->id,
                'invited_by' => $organizer->id,
                'invited_email' => $email,
                'invitation_status' => 'pending',
                'sent_at' => now(),
            ]);

            $ics = IcsGenerator::forMeeting(
                $meeting->uuid,
                $meeting->title,
                $meeting->description,
                $meeting->scheduled_start,
                $meeting->scheduled_end,
                $organizer->email,
                $organizer->full_name,
                $guestJoinUrl,
                $email,
            );

            Mail::to($email)->send(new MeetingInvitationMail($meeting, $organizer->full_name, $guestJoinUrl, $ics));
        }

        return $event;
    }

    /**
     * A personal/reminder/holiday/task calendar entry with no linked
     * Meeting - unlike scheduleMeeting(), this needs no invitations,
     * RSVPs, reminders, or ICS mail, since there's no one else to
     * notify. created_by is the sole owner (mirrors reschedule()'s
     * own $event->meeting_id ? ... : $event->created_by fallback).
     */
    public function createStandaloneEvent(User $user, array $data): CalendarEvent
    {
        return CalendarEvent::create([
            'created_by' => $user->id,
            'meeting_id' => null,
            'event_type' => $data['event_type'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'],
            'timezone' => $data['timezone'],
            'status' => 'scheduled',
        ]);
    }

    /**
     * Only for meeting-less events - a meeting-backed calendar event
     * is removed by cancelling the meeting itself (MeetingService),
     * which already handles closing out invitations/participants;
     * this endpoint has no equivalent cleanup for that path. Marks
     * 'cancelled' rather than a hard delete - calendar_events has no
     * soft-delete column, and eventsInRange()/upcomingEvents() already
     * exclude cancelled/archived rows, so this is consistent with how
     * the rest of the calendar treats removal.
     */
    public function deleteStandaloneEvent(CalendarEvent $event, User $actor): void
    {
        if ($event->meeting_id !== null) {
            throw new AuthorizationException('Cancel the meeting instead of deleting its calendar event directly.');
        }

        if ($event->created_by !== $actor->id) {
            throw new AuthorizationException('Only the creator may delete this event.');
        }

        $event->forceFill(['status' => 'cancelled'])->save();
    }

    /**
     * FR-169 RSVP.
     */
    public function rsvp(CalendarEvent $event, User $user, string $status): CalendarRsvp
    {
        $rsvp = CalendarRsvp::updateOrCreate(
            ['calendar_event_id' => $event->id, 'user_id' => $user->id],
            ['status' => $status],
        );

        if ($event->meeting_id) {
            MeetingInvitation::where('meeting_id', $event->meeting_id)
                ->where('invited_user_id', $user->id)
                ->update([
                    'invitation_status' => $status,
                    'accepted_at' => $status === 'accepted' ? now() : null,
                    'declined_at' => $status === 'declined' ? now() : null,
                ]);
        }

        return $rsvp;
    }

    public function upcomingEvents(User $user): \Illuminate\Support\Collection
    {
        return CalendarEvent::where(function ($q) use ($user) {
            $q->where('created_by', $user->id)
                ->orWhereHas('rsvps', fn ($q2) => $q2->where('user_id', $user->id));
        })
            ->where('start_at', '>=', now()->subHour())
            ->whereNotIn('status', ['cancelled', 'archived'])
            ->orderBy('start_at')
            ->get();
    }

    /**
     * Backs real month/week/day browsing (past AND future, unlike
     * upcomingEvents() above) - same ownership rule, just an arbitrary
     * date window instead of a hardcoded "from now on" floor.
     */
    public function eventsInRange(User $user, Carbon $start, Carbon $end): \Illuminate\Support\Collection
    {
        return CalendarEvent::where(function ($q) use ($user) {
            $q->where('created_by', $user->id)
                ->orWhereHas('rsvps', fn ($q2) => $q2->where('user_id', $user->id));
        })
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->whereNotIn('status', ['cancelled', 'archived'])
            ->orderBy('start_at')
            ->get();
    }

    /**
     * Drag-to-reschedule. CalendarEvent.start_at/end_at are only ever
     * copied from Meeting.scheduled_start/scheduled_end once, at
     * scheduleMeeting() time - nothing keeps them in sync afterward,
     * so a reschedule must update both atomically or they'd drift
     * apart (the calendar would show one time, the meeting room
     * another). Mirrors cancel()'s organizer-only check (Carbon-typed
     * to match the timezone-agnostic column casts).
     */
    public function reschedule(CalendarEvent $event, User $actor, Carbon $newStart, Carbon $newEnd): CalendarEvent
    {
        $ownerId = $event->meeting_id ? $event->meeting->organizer_id : $event->created_by;

        if ($ownerId !== $actor->id) {
            throw new AuthorizationException('Only the organizer may reschedule this event.');
        }

        DB::transaction(function () use ($event, $newStart, $newEnd) {
            $event->forceFill(['start_at' => $newStart, 'end_at' => $newEnd])->save();

            if ($event->meeting_id) {
                $event->meeting->forceFill(['scheduled_start' => $newStart, 'scheduled_end' => $newEnd])->save();
            }
        });

        return $event;
    }

    /**
     * FR-166 - sends any due reminders that haven't fired yet. Called
     * from a scheduled job (see Jobs/SendCalendarRemindersJob).
     *
     * Delivers via the same two channels every other real-time
     * notification in this app already uses (push and an in-chat system
     * message - see MessageService::send()'s push call and CallService's
     * call-log system message) rather than a bespoke third mechanism.
     * Push fires for every reminder (meeting-linked or a standalone
     * personal one); the in-chat message only applies when the
     * reminder is for a meeting invite, since that's the only case with
     * a real organizer<->invitee conversation to post into.
     */
    public function sendDueReminders(): int
    {
        $due = CalendarReminder::whereNull('sent_at')
            ->with(['event.meeting.organizer', 'user.preferences'])
            ->get()
            ->filter(fn (CalendarReminder $reminder) => $reminder->event
                && now()->greaterThanOrEqualTo($reminder->event->start_at->clone()->subMinutes($reminder->remind_before_minutes)));

        foreach ($due as $reminder) {
            $event = $reminder->event;
            $user = $reminder->user;
            $minutes = $reminder->remind_before_minutes;
            $body = "\"{$event->title}\" starts in {$minutes} minute".($minutes === 1 ? '' : 's').'.';

            if ($user->preferences?->notify_meetings ?? true) {
                $this->push->sendToUser($user, 'Meeting reminder', $body, [
                    'type' => 'meeting_reminder',
                    'calendar_event_id' => $event->id,
                    'meeting_id' => $event->meeting_id,
                ]);
            }

            $organizer = $event->meeting?->organizer;
            if ($organizer && $organizer->id !== $user->id) {
                // Best-effort, like the push send above - e.g. a block
                // between organizer and invitee since scheduling must
                // not stop every other due reminder from being marked sent.
                try {
                    $conversation = $this->conversations->startPrivateConversation($organizer, $user->id);
                    $this->messages->create($conversation, $organizer, ['message_type' => 'system', 'body' => 'Reminder: '.$body]);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $reminder->forceFill(['sent_at' => now()])->save();
        }

        return $due->count();
    }
}
