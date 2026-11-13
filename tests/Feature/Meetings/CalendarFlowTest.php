<?php

namespace Tests\Feature\Meetings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Models\User;
use Modules\Meetings\Jobs\SendCalendarRemindersJob;
use Modules\Meetings\Mail\MeetingInvitationMail;
use Modules\Meetings\Models\CalendarReminder;
use Modules\Meetings\Models\MeetingInvitation;
use Tests\TestCase;

class CalendarFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(): User
    {
        return User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    }

    public function test_scheduling_a_meeting_invites_an_ad_hoc_email_that_has_no_account(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'External sync',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
            'participant_emails' => ['no-account@example.com'],
        ])->json('data.id');

        $this->assertDatabaseHas('meeting_invitations', [
            'meeting_id' => $meetingId,
            'invited_user_id' => null,
            'invited_email' => 'no-account@example.com',
        ]);

        Mail::assertSent(MeetingInvitationMail::class, function (MeetingInvitationMail $mail) {
            return str_contains($mail->icsContent, 'ATTENDEE;RSVP=TRUE:mailto:no-account@example.com')
                && str_contains($mail->joinUrl, '/j/');
        });
    }

    /**
     * Regression test: the web scheduling form always matched invited
     * emails against existing users, but the API's /api/v1/meetings
     * schedule() endpoint used to pass participant_emails straight
     * through as ad-hoc invites even when the email belonged to a real
     * registered user - they'd get an invited_email-only invitation
     * with no invited_user_id/RSVP/reminder/in-app treatment at all.
     */
    public function test_scheduling_a_meeting_via_the_api_by_email_matches_an_existing_user_the_same_way_the_web_form_does(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $invitee = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Email-matched invite',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
            'participant_emails' => [$invitee->email],
        ])->json('data.id');

        $this->assertDatabaseHas('meeting_invitations', [
            'meeting_id' => $meetingId,
            'invited_user_id' => $invitee->id,
            'invited_email' => null,
        ]);
        $this->assertDatabaseHas('calendar_rsvp', ['user_id' => $invitee->id, 'status' => 'pending']);
        $this->assertDatabaseHas('calendar_reminders', ['user_id' => $invitee->id]);

        Mail::assertSent(MeetingInvitationMail::class, fn ($mail) => $mail->hasTo($invitee->email));
    }

    public function test_scheduling_a_meeting_lists_it_in_the_invitees_upcoming_calendar_events(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $invitee = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Board sync',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
            'participant_ids' => [$invitee->id],
        ])->json('data.id');

        $events = $this->actingAs($invitee, 'sanctum')->getJson('/api/v1/calendar/events');
        $events->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($meetingId, $events->json('data.0.meeting_id'));
        $this->assertSame('pending', $events->json('data.0.my_rsvp'));

        // A code alone isn't tappable/pasteable into a chat message the
        // way a real /j/{code} link is - the calendar event popup's
        // "Copy link" button needs this to build that link client-side.
        $this->assertNotNull($events->json('data.0.meeting_code'));
        $this->assertSame(\Modules\Meetings\Models\Meeting::find($meetingId)->meeting_code, $events->json('data.0.meeting_code'));
    }

    public function test_rsvp_updates_calendar_and_meeting_invitation_status(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $invitee = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Retro',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
            'participant_ids' => [$invitee->id],
        ])->json('data.id');

        $eventId = $this->actingAs($invitee, 'sanctum')->getJson('/api/v1/calendar/events')->json('data.0.id');

        $this->actingAs($invitee, 'sanctum')->postJson("/api/v1/calendar/events/{$eventId}/rsvp", ['status' => 'accepted'])->assertStatus(200);

        $this->assertDatabaseHas('calendar_rsvp', ['calendar_event_id' => $eventId, 'user_id' => $invitee->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('meeting_invitations', ['meeting_id' => $meetingId, 'invited_user_id' => $invitee->id, 'invitation_status' => 'accepted']);
    }

    public function test_ics_endpoint_returns_a_valid_calendar_file(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $invitee = $this->activeUser();

        $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'ICS test',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
            'participant_ids' => [$invitee->id],
        ]);

        $eventId = $this->actingAs($invitee, 'sanctum')->getJson('/api/v1/calendar/events')->json('data.0.id');

        $response = $this->actingAs($invitee, 'sanctum')->getJson("/api/v1/calendar/events/{$eventId}/ics");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/calendar; charset=utf-8');
        $ics = $response->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('SUMMARY:ICS test', $ics);
        $this->assertStringContainsString('ATTENDEE;RSVP=TRUE:mailto:'.$invitee->email, $ics);
        $this->assertMatchesRegularExpression('/URL:https?:\/\/\S+\/meetings\/\d+/', $ics);

        preg_match('/URL:(\S+)/', $ics, $matches);
        $this->actingAs($invitee, 'sanctum')->get($matches[1])->assertSuccessful();
    }

    public function test_send_calendar_reminders_job_marks_due_reminders_sent_and_leaves_future_ones_alone(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $invitee = $this->activeUser();

        $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Reminder test',
            'scheduled_start' => now()->addMinutes(25)->toISOString(),
            'scheduled_end' => now()->addMinutes(55)->toISOString(),
            'timezone' => 'UTC',
            'participant_ids' => [$invitee->id],
        ]);

        // Default reminder is config('meetings.default_reminder_minutes') = 15
        // minutes before start; the meeting starts in 25 minutes, so it is
        // not due yet.
        (new SendCalendarRemindersJob())->handle(app(\Modules\Meetings\Services\CalendarService::class));
        $this->assertDatabaseHas('calendar_reminders', ['user_id' => $invitee->id, 'sent_at' => null]);

        $reminder = CalendarReminder::where('user_id', $invitee->id)->first();
        $reminder->event->forceFill(['start_at' => now()->addMinutes(5)])->save();

        (new SendCalendarRemindersJob())->handle(app(\Modules\Meetings\Services\CalendarService::class));
        $this->assertNotNull($reminder->fresh()->sent_at);
    }

    /**
     * The reminder used to only flip sent_at with no actual delivery -
     * this confirms the invitee genuinely receives an in-chat system
     * message from the organizer, in their real 1:1 conversation, not
     * just a silently-marked-sent database row.
     */
    public function test_a_due_meeting_reminder_posts_an_in_chat_system_message_from_the_organizer_to_the_invitee(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $invitee = $this->activeUser();

        $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Chat reminder test',
            'scheduled_start' => now()->addMinutes(5)->toISOString(),
            'scheduled_end' => now()->addMinutes(35)->toISOString(),
            'timezone' => 'UTC',
            'participant_ids' => [$invitee->id],
        ])->assertStatus(201);

        (new SendCalendarRemindersJob())->handle(app(\Modules\Meetings\Services\CalendarService::class));

        $conversation = \Modules\Chat\Models\Conversation::where('type', 'private')
            ->whereHas('members', fn ($q) => $q->where('user_id', $organizer->id))
            ->whereHas('members', fn ($q) => $q->where('user_id', $invitee->id))
            ->first();
        $this->assertNotNull($conversation, 'expected a private conversation between organizer and invitee to exist');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'sender_id' => $organizer->id,
            'message_type' => 'system',
        ]);

        $message = \Modules\Chat\Models\Message::where('conversation_id', $conversation->id)->where('message_type', 'system')->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString('Chat reminder test', $message->body);
    }

    public function test_events_endpoint_with_a_date_range_can_find_a_past_event_the_upcoming_only_default_would_miss(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $invitee = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Already happened',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
            'participant_ids' => [$invitee->id],
        ])->json('data.id');

        $eventId = \Modules\Meetings\Models\CalendarEvent::where('meeting_id', $meetingId)->firstOrFail()->id;
        \Modules\Meetings\Models\CalendarEvent::whereKey($eventId)->update([
            'start_at' => now()->subMonth(),
            'end_at' => now()->subMonth()->addHour(),
        ]);

        // The plain "upcoming" default must not see it anymore.
        $this->actingAs($invitee, 'sanctum')->getJson('/api/v1/calendar/events')->assertJsonCount(0, 'data');

        // But an explicit range covering last month does.
        $response = $this->actingAs($invitee, 'sanctum')->getJson('/api/v1/calendar/events?start='.now()->subMonths(2)->toISOString().'&end='.now()->toISOString());
        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($eventId, $response->json('data.0.id'));
    }

    public function test_organizer_can_reschedule_a_meeting_and_both_the_event_and_meeting_move_together(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $invitee = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Movable meeting',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
            'participant_ids' => [$invitee->id],
        ])->json('data.id');

        $eventId = \Modules\Meetings\Models\CalendarEvent::where('meeting_id', $meetingId)->firstOrFail()->id;
        $newStart = now()->addDays(3);
        $newEnd = now()->addDays(3)->addHour();

        $response = $this->actingAs($organizer, 'sanctum')->patchJson("/api/v1/calendar/events/{$eventId}/reschedule", [
            'start_at' => $newStart->toISOString(),
            'end_at' => $newEnd->toISOString(),
        ]);
        $response->assertStatus(200);

        $this->assertSame($newStart->format('Y-m-d H:i:s'), \Modules\Meetings\Models\CalendarEvent::find($eventId)->start_at->format('Y-m-d H:i:s'));
        $this->assertSame($newStart->format('Y-m-d H:i:s'), \Modules\Meetings\Models\Meeting::find($meetingId)->scheduled_start->format('Y-m-d H:i:s'));
    }

    public function test_a_non_organizer_invitee_cannot_reschedule_a_meeting(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $invitee = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Not yours to move',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
            'participant_ids' => [$invitee->id],
        ])->json('data.id');

        $eventId = \Modules\Meetings\Models\CalendarEvent::where('meeting_id', $meetingId)->firstOrFail()->id;

        $this->actingAs($invitee, 'sanctum')->patchJson("/api/v1/calendar/events/{$eventId}/reschedule", [
            'start_at' => now()->addDays(3)->toISOString(),
            'end_at' => now()->addDays(3)->addHour()->toISOString(),
        ])->assertStatus(403);
    }

    public function test_dragging_the_resize_handle_only_changes_duration_via_the_same_reschedule_endpoint(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Resizable meeting',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
        ])->json('data.id');

        $eventId = \Modules\Meetings\Models\CalendarEvent::where('meeting_id', $meetingId)->firstOrFail()->id;
        $originalStart = \Modules\Meetings\Models\CalendarEvent::find($eventId)->start_at;
        $newEnd = $originalStart->clone()->addHours(2);

        $this->actingAs($organizer, 'sanctum')->patchJson("/api/v1/calendar/events/{$eventId}/reschedule", [
            'start_at' => $originalStart->toISOString(),
            'end_at' => $newEnd->toISOString(),
        ])->assertStatus(200);

        $event = \Modules\Meetings\Models\CalendarEvent::find($eventId);
        $this->assertSame($originalStart->format('Y-m-d H:i:s'), $event->start_at->format('Y-m-d H:i:s'));
        $this->assertSame($newEnd->format('Y-m-d H:i:s'), $event->end_at->format('Y-m-d H:i:s'));
        $this->assertSame($newEnd->format('Y-m-d H:i:s'), \Modules\Meetings\Models\Meeting::find($meetingId)->scheduled_end->format('Y-m-d H:i:s'));
    }

    /**
     * A real Chrome/OS combination can resolve
     * Intl.DateTimeFormat().resolvedOptions().timeZone to a legacy IANA
     * alias (e.g. "Asia/Calcutta", not the modern "Asia/Kolkata") -
     * PHP's default `timezone` validation rule group rejects these
     * outright, only `timezone:all_with_bc` accepts them (the same
     * fix already applied in UpdatePreferencesRequest). Caught via a
     * real Puppeteer repro before this regression test was added.
     */
    public function test_standalone_event_creation_accepts_a_legacy_backward_compatible_timezone_alias(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/calendar/events', [
            'title' => 'Legacy tz alias',
            'event_type' => 'personal',
            'start_at' => now()->addDay()->toISOString(),
            'end_at' => now()->addDay()->addMinutes(30)->toISOString(),
            'timezone' => 'Asia/Calcutta',
        ])->assertStatus(201);
    }

    public function test_a_user_can_create_a_standalone_personal_reminder_with_no_linked_meeting(): void
    {
        $user = $this->activeUser();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/calendar/events', [
            'title' => 'Renew passport',
            'event_type' => 'reminder',
            'start_at' => now()->addWeek()->toISOString(),
            'end_at' => now()->addWeek()->addMinutes(30)->toISOString(),
            'timezone' => 'UTC',
        ]);
        $response->assertStatus(201);

        $eventId = $response->json('data.id');
        $this->assertDatabaseHas('calendar_events', [
            'id' => $eventId,
            'created_by' => $user->id,
            'meeting_id' => null,
            'event_type' => 'reminder',
            'title' => 'Renew passport',
        ]);

        $events = $this->actingAs($user, 'sanctum')->getJson('/api/v1/calendar/events');
        $this->assertSame($eventId, $events->json('data.0.id'));
    }

    public function test_standalone_event_creation_rejects_the_meeting_event_type(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/calendar/events', [
            'title' => 'Not allowed this way',
            'event_type' => 'meeting',
            'start_at' => now()->addWeek()->toISOString(),
            'end_at' => now()->addWeek()->addHour()->toISOString(),
            'timezone' => 'UTC',
        ])->assertStatus(422)->assertJsonValidationErrors('event_type');
    }

    public function test_the_creator_can_delete_their_own_standalone_event(): void
    {
        $user = $this->activeUser();

        $eventId = $this->actingAs($user, 'sanctum')->postJson('/api/v1/calendar/events', [
            'title' => 'Throwaway task',
            'event_type' => 'task',
            'start_at' => now()->addDay()->toISOString(),
            'end_at' => now()->addDay()->addMinutes(20)->toISOString(),
            'timezone' => 'UTC',
        ])->json('data.id');

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/calendar/events/{$eventId}")->assertStatus(200);

        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'status' => 'cancelled']);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/calendar/events')->assertJsonCount(0, 'data');
    }

    public function test_a_user_cannot_delete_another_users_standalone_event(): void
    {
        $owner = $this->activeUser();
        $intruder = $this->activeUser();

        $eventId = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/calendar/events', [
            'title' => 'Mine, not yours',
            'event_type' => 'personal',
            'start_at' => now()->addDay()->toISOString(),
            'end_at' => now()->addDay()->addMinutes(30)->toISOString(),
            'timezone' => 'UTC',
        ])->json('data.id');

        $this->actingAs($intruder, 'sanctum')->deleteJson("/api/v1/calendar/events/{$eventId}")->assertStatus(403);
        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'status' => 'scheduled']);
    }

    public function test_a_meeting_backed_event_cannot_be_deleted_via_the_standalone_delete_endpoint(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Real meeting',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
        ])->json('data.id');

        $eventId = \Modules\Meetings\Models\CalendarEvent::where('meeting_id', $meetingId)->firstOrFail()->id;

        $this->actingAs($organizer, 'sanctum')->deleteJson("/api/v1/calendar/events/{$eventId}")->assertStatus(403);
        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'status' => 'scheduled']);
    }
}
