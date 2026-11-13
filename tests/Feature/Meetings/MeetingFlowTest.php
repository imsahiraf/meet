<?php

namespace Tests\Feature\Meetings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Models\User;
use Modules\Meetings\Mail\MeetingInvitationMail;
use Modules\Meetings\Models\Meeting;
use Tests\TestCase;

class MeetingFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    /**
     * The host is NOT auto-added as a participant at creation time -
     * like every other participant they go through the room's own
     * lobby first (device preview, camera/mic permission prompt,
     * explicit "Join meeting" click), same as real Google Meet.
     * Auto-joining immediately used to make the host's very own
     * first-ever page load indistinguishable from "refreshed mid-call,
     * rejoin automatically" and drop them straight into the room with
     * no chance to grant/decline device access first.
     */
    public function test_instant_meeting_does_not_auto_join_the_host_until_they_explicitly_join(): void
    {
        $host = $this->activeUser();

        $response = $this->actingAs($host, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Standup']);

        $response->assertStatus(201)->assertJsonPath('data.status', 'active')->assertJsonPath('data.meeting_type', 'instant');
        $meetingId = $response->json('data.id');

        $this->assertDatabaseMissing('meeting_participants', ['meeting_id' => $meetingId, 'user_id' => $host->id]);
        $this->assertDatabaseHas('meeting_chat', ['meeting_id' => $meetingId]);
    }

    public function test_the_room_page_shows_the_lobby_not_an_auto_rejoin_right_after_creating_an_instant_meeting(): void
    {
        $host = $this->activeUser();
        $meetingId = $this->actingAs($host, 'sanctum')
            ->postJson('/api/v1/meetings/instant', ['title' => 'Standup'])
            ->json('data.id');

        $this->actingAs($host, 'web')->get("/meetings/{$meetingId}")
            ->assertOk()
            ->assertSee('data-already-joined="0"', false);

        // Explicitly joining (the lobby's "Join meeting" click) is what
        // actually admits them, at which point a real refresh SHOULD
        // skip straight back into the room.
        $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join")->assertStatus(200);

        $this->actingAs($host, 'web')->get("/meetings/{$meetingId}")
            ->assertOk()
            ->assertSee('data-already-joined="1"', false);
    }

    public function test_only_verified_users_may_schedule_a_meeting(): void
    {
        $unverified = $this->activeUser(['email_verified_at' => null]);

        $this->actingAs($unverified, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Planning',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
        ])->assertStatus(403);
    }

    public function test_scheduling_a_meeting_creates_calendar_event_invitations_rsvp_and_queues_ics_email(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $invitee = $this->activeUser();

        $response = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Quarterly Review',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
            'participant_ids' => [$invitee->id],
        ]);

        $response->assertStatus(201)->assertJsonPath('data.meeting_type', 'scheduled');
        $meetingId = $response->json('data.id');

        $this->assertDatabaseHas('calendar_events', ['meeting_id' => $meetingId, 'event_type' => 'meeting']);
        $this->assertDatabaseHas('meeting_invitations', ['meeting_id' => $meetingId, 'invited_user_id' => $invitee->id, 'invitation_status' => 'pending']);
        $this->assertDatabaseHas('calendar_rsvp', ['user_id' => $invitee->id, 'status' => 'pending']);
        $this->assertDatabaseHas('calendar_reminders', ['user_id' => $invitee->id]);

        Mail::assertSent(MeetingInvitationMail::class, fn ($mail) => $mail->hasTo($invitee->email));
    }

    public function test_join_without_waiting_room_admits_immediately(): void
    {
        $organizer = $this->activeUser();
        $participant = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')
            ->postJson('/api/v1/meetings', [
                'title' => 'Open meeting',
                'scheduled_start' => now()->addHour()->toISOString(),
                'scheduled_end' => now()->addHours(2)->toISOString(),
                'timezone' => 'UTC',
                'waiting_room_enabled' => false,
            ])->json('data.id');

        $response = $this->actingAs($participant, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");
        $response->assertStatus(200)->assertJsonPath('data.status', 'joined');

        $this->assertDatabaseHas('meeting_participants', ['meeting_id' => $meetingId, 'user_id' => $participant->id, 'role' => 'participant']);
        $this->assertDatabaseHas('meetings', ['id' => $meetingId, 'status' => 'active']);
    }

    public function test_join_with_waiting_room_holds_for_host_approval(): void
    {
        $organizer = $this->activeUser();
        $participant = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Locked lobby'])->json('data.id');
        Meeting::where('id', $meetingId)->update(['waiting_room_enabled' => true]);
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");

        $joinResponse = $this->actingAs($participant, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");
        $joinResponse->assertStatus(200)->assertJsonPath('data.status', 'waiting');
        $waitingRoomId = $joinResponse->json('data.waiting_room_id');

        $this->assertDatabaseMissing('meeting_participants', ['meeting_id' => $meetingId, 'user_id' => $participant->id]);

        $this->actingAs($participant, 'sanctum')
            ->getJson("/api/v1/meetings/{$meetingId}/waiting-room/mine")
            ->assertJsonPath('data.status', 'waiting');

        $admitResponse = $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/waiting-room/{$waitingRoomId}/admit");
        $admitResponse->assertStatus(200);

        $this->assertDatabaseHas('meeting_participants', ['meeting_id' => $meetingId, 'user_id' => $participant->id]);
        $this->actingAs($participant, 'sanctum')
            ->getJson("/api/v1/meetings/{$meetingId}/waiting-room/mine")
            ->assertJsonPath('data.status', 'admitted');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $organizer = $this->activeUser();
        $participant = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Private',
            'scheduled_start' => now()->addHour()->toISOString(),
            'scheduled_end' => now()->addHours(2)->toISOString(),
            'timezone' => 'UTC',
            'password' => 'secret123',
        ])->json('data.id');

        $this->actingAs($participant, 'sanctum')
            ->postJson("/api/v1/meetings/{$meetingId}/join", ['password' => 'wrong'])
            ->assertStatus(403);
    }

    public function test_guest_can_join_without_registering_when_guest_mode_enabled(): void
    {
        $organizer = $this->activeUser();
        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Open house'])->json('data.id');
        Meeting::where('id', $meetingId)->update(['waiting_room_enabled' => false]);

        $response = $this->postJson("/api/v1/meetings/{$meetingId}/guest", ['guest_name' => 'Visitor Vic']);
        $response->assertStatus(201)->assertJsonPath('data.status', 'joined');

        $token = $response->json('data.guest_session.session_token');
        $this->assertNotEmpty($token);
        $this->assertDatabaseHas('meeting_guest_sessions', ['meeting_id' => $meetingId, 'guest_name' => 'Visitor Vic', 'status' => 'active']);

        $this->getJson("/api/v1/meetings/guest/{$token}/status")->assertJsonPath('data.status', 'active');
    }

    public function test_guest_join_with_waiting_room_is_admitted_by_host(): void
    {
        $organizer = $this->activeUser();
        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Guarded'])->json('data.id');
        Meeting::where('id', $meetingId)->update(['waiting_room_enabled' => true]);
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");

        $joinResponse = $this->postJson("/api/v1/meetings/{$meetingId}/guest", ['guest_name' => 'Waiting Wendy']);
        $token = $joinResponse->json('data.guest_session.session_token');

        $this->getJson("/api/v1/meetings/guest/{$token}/status")->assertJsonPath('data.status', 'pending');

        $entryId = \Modules\Meetings\Models\MeetingWaitingRoom::where('meeting_id', $meetingId)->where('guest_name', 'Waiting Wendy')->first()->id;
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/waiting-room/{$entryId}/admit")->assertStatus(200);

        $status = $this->getJson("/api/v1/meetings/guest/{$token}/status");
        $status->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('meeting_participants', ['guest_session_id' => \Modules\Meetings\Models\MeetingGuestSession::where('session_token', $token)->first()->id]);
    }

    /**
     * The guest room's own lock button was previously entirely missing
     * from the markup, and there was no backend route for it at all -
     * a guest-created meeting's creator is its host too (see
     * guestCreateInstant()), so they need a way to lock it exactly
     * like a registered host does.
     */
    public function test_the_guest_host_of_a_self_created_meeting_can_lock_and_unlock_it(): void
    {
        $create = $this->postJson('/meetings/guest/new', ['guest_name' => 'Host Holly']);
        $create->assertRedirect();
        $token = str($create->headers->get('Location'))->afterLast('/')->toString();

        $this->postJson("/api/v1/meetings/guest/{$token}/lock", ['locked' => true])->assertStatus(200)
            ->assertJsonPath('data.status', 'locked');

        $meetingId = \Modules\Meetings\Models\MeetingGuestSession::where('session_token', $token)->first()->meeting_id;
        $latecomer = $this->activeUser();
        $this->actingAs($latecomer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join")->assertStatus(409);

        $this->postJson("/api/v1/meetings/guest/{$token}/lock", ['locked' => false])->assertStatus(200)
            ->assertJsonPath('data.status', 'active');
    }

    /**
     * A non-host guest (joined someone else's guest-created meeting,
     * not its creator) must not be able to lock it.
     */
    public function test_a_non_host_guest_cannot_lock_a_meeting(): void
    {
        $create = $this->postJson('/meetings/guest/new', ['guest_name' => 'Host Holly']);
        $hostToken = str($create->headers->get('Location'))->afterLast('/')->toString();
        $meetingId = \Modules\Meetings\Models\MeetingGuestSession::where('session_token', $hostToken)->first()->meeting_id;

        $joinResponse = $this->postJson("/api/v1/meetings/{$meetingId}/guest", ['guest_name' => 'Plain Pat']);
        $memberToken = $joinResponse->json('data.guest_session.session_token');

        $this->postJson("/api/v1/meetings/guest/{$memberToken}/lock", ['locked' => true])->assertStatus(403);
    }

    /**
     * The guest room's Share screen button existed in the markup but
     * had no JS wiring, and there was no backend route for it either -
     * it now rides the exact same MeetingService::startScreenShare()/
     * stopScreenShare() the registered room already uses.
     */
    public function test_a_guest_can_start_and_stop_screen_sharing(): void
    {
        $create = $this->postJson('/meetings/guest/new', ['guest_name' => 'Host Holly']);
        $token = str($create->headers->get('Location'))->afterLast('/')->toString();

        $this->postJson("/api/v1/meetings/guest/{$token}/screen-share/start")->assertStatus(201);

        $session = \Modules\Meetings\Models\MeetingGuestSession::where('session_token', $token)->first();
        $this->assertDatabaseHas('meeting_participants', ['guest_session_id' => $session->id, 'screen_sharing' => true]);

        $this->postJson("/api/v1/meetings/guest/{$token}/screen-share/stop")->assertStatus(200);
        $this->assertDatabaseHas('meeting_participants', ['guest_session_id' => $session->id, 'screen_sharing' => false]);
    }

    public function test_guest_access_can_be_disabled(): void
    {
        $organizer = $this->activeUser();
        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'No guests'])->json('data.id');
        Meeting::where('id', $meetingId)->update(['guest_enabled' => false]);

        $this->postJson("/api/v1/meetings/{$meetingId}/guest", ['guest_name' => 'Uninvited'])->assertStatus(422);
    }

    public function test_host_can_mute_remove_and_manage_co_host_roles(): void
    {
        $organizer = $this->activeUser();
        $member = $this->activeUser();
        $stranger = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Team sync'])->json('data.id');
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join")->assertStatus(200);
        $participantId = \Modules\Meetings\Models\MeetingParticipant::where('meeting_id', $meetingId)->where('user_id', $member->id)->first()->id;

        // A stranger cannot manage participants.
        $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/participants/{$participantId}/mute")->assertStatus(403);

        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/participants/{$participantId}/mute")->assertStatus(200);
        $this->assertDatabaseHas('meeting_participants', ['id' => $participantId, 'microphone_enabled' => false]);

        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/participants/{$participantId}/co-host")->assertStatus(200);
        $this->assertDatabaseHas('meeting_participants', ['id' => $participantId, 'role' => 'co_host']);

        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/participants/{$participantId}/revoke-co-host")->assertStatus(200);
        $this->assertDatabaseHas('meeting_participants', ['id' => $participantId, 'role' => 'participant']);

        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/participants/{$participantId}/remove")->assertStatus(200);
        $this->assertDatabaseHas('meeting_participants', ['id' => $participantId]);
        $this->assertNotNull(\Modules\Meetings\Models\MeetingParticipant::find($participantId)->left_at);
    }

    public function test_locking_a_meeting_blocks_new_joins(): void
    {
        $organizer = $this->activeUser();
        $latecomer = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Locked'])->json('data.id');
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/lock", ['locked' => true])->assertStatus(200);

        $this->actingAs($latecomer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join")->assertStatus(409);
    }

    public function test_ending_a_meeting_leaves_all_participants_and_records_a_report(): void
    {
        $organizer = $this->activeUser();
        $member = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Wrap up'])->json('data.id');
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");

        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/end")->assertStatus(200)->assertJsonPath('data.status', 'ended');

        $this->assertDatabaseHas('meeting_reports', ['meeting_id' => $meetingId, 'total_participants' => 2]);
        $this->assertDatabaseMissing('meeting_participants', ['meeting_id' => $meetingId, 'user_id' => $member->id, 'left_at' => null]);
    }

    public function test_only_one_participant_may_screen_share_at_a_time(): void
    {
        $organizer = $this->activeUser();
        $member = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Demo'])->json('data.id');
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");
        \Modules\Meetings\Models\MeetingSetting::where('meeting_id', $meetingId)->update(['screen_share_permission' => 'all_participants']);

        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/screen-share/start")->assertStatus(201);
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/screen-share/start")->assertStatus(409);

        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/screen-share/stop")->assertStatus(200);
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/screen-share/start")->assertStatus(201);
    }

    public function test_mesh_signaling_is_scoped_per_participant_pair(): void
    {
        $organizer = $this->activeUser();
        $member = $this->activeUser();
        $stranger = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Trio'])->json('data.id');
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");

        $hostParticipantId = \Modules\Meetings\Models\MeetingParticipant::where('meeting_id', $meetingId)->where('user_id', $organizer->id)->first()->id;
        $memberParticipantId = \Modules\Meetings\Models\MeetingParticipant::where('meeting_id', $meetingId)->where('user_id', $member->id)->first()->id;

        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/offer", [
            'receiver_participant_id' => $memberParticipantId,
            'sdp' => 'fake-offer',
        ])->assertStatus(201);

        $poll = $this->actingAs($member, 'sanctum')->getJson("/api/v1/meetings/{$meetingId}/signals?after_signal_id=0&after_ice_id=0");
        $poll->assertStatus(200);
        $this->assertCount(1, $poll->json('data.signals'));
        $this->assertSame($hostParticipantId, $poll->json('data.signals.0.sender_participant_id'));

        // A stranger cannot even poll/act on a meeting they're not part of.
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/meetings/{$meetingId}/signals")->assertStatus(403);
    }

    public function test_history_and_upcoming_listings(): void
    {
        $organizer = $this->activeUser();

        $instantId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Done'])->json('data.id');
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$instantId}/join");
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$instantId}/end");

        $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Future',
            'scheduled_start' => now()->addDay()->toISOString(),
            'scheduled_end' => now()->addDay()->addHour()->toISOString(),
            'timezone' => 'UTC',
        ]);

        $this->actingAs($organizer, 'sanctum')->getJson('/api/v1/meetings/history')->assertJsonCount(1, 'data');
        $this->actingAs($organizer, 'sanctum')->getJson('/api/v1/meetings/upcoming')->assertJsonCount(1, 'data');
    }

    /**
     * Real bug: the web schedule form's <input type="datetime-local">
     * submits a plain wall-clock string with no UTC offset (e.g. from
     * a browser in Asia/Kolkata, UTC+5:30) - naively parsing that as
     * APP_TIMEZONE (UTC) silently shifted every scheduled meeting by
     * the organizer's own UTC offset. MeetingService::createScheduled()
     * must interpret it using the submitted timezone field instead.
     */
    public function test_scheduling_with_a_naive_datetime_uses_the_submitted_timezone_not_app_timezone(): void
    {
        $organizer = $this->activeUser();

        // A naive local wall-clock string (no offset), as a browser's
        // datetime-local input would submit it, for "1 hour from now
        // in Asia/Kolkata" - if this were wrongly parsed as UTC, the
        // stored instant would land ~5.5 hours later than intended.
        $localNow = now()->setTimezone('Asia/Kolkata')->addHour();
        $naiveStart = $localNow->format('Y-m-d\TH:i');
        $naiveEnd = $localNow->copy()->addHour()->format('Y-m-d\TH:i');

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Timezone Check',
            'scheduled_start' => $naiveStart,
            'scheduled_end' => $naiveEnd,
            'timezone' => 'Asia/Kolkata',
        ])->json('data.id');

        $meeting = \Modules\Meetings\Models\Meeting::find($meetingId);

        $this->assertSame(
            $localNow->clone()->utc()->format('Y-m-d H:i'),
            $meeting->scheduled_start->utc()->format('Y-m-d H:i'),
        );
    }

    /**
     * Real bug this activated: a participant who abandons the tab
     * (crash, force-quit, network drop) rather than clicking Leave has
     * no left_at set, so they used to stay in the roster - and
     * therefore render as a phantom video tile - forever. Polling the
     * roster is how a live participant heartbeats themselves; anyone
     * who stops doing that should age out.
     */
    public function test_a_participant_who_stops_heartbeating_disappears_from_the_roster(): void
    {
        $organizer = $this->activeUser();
        $participant = $this->activeUser();

        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Heartbeat check'])->json('data.id');
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");
        $this->actingAs($participant, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");

        $roster = $this->actingAs($organizer, 'sanctum')->getJson("/api/v1/meetings/{$meetingId}/participants");
        $roster->assertStatus(200)->assertJsonCount(2, 'data');

        \Modules\Meetings\Models\MeetingParticipant::where('meeting_id', $meetingId)
            ->where('user_id', $participant->id)
            ->update(['last_seen_at' => now()->subSeconds(120)]);

        $staleRoster = $this->actingAs($organizer, 'sanctum')->getJson("/api/v1/meetings/{$meetingId}/participants");
        $staleRoster->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($organizer->id, $staleRoster->json('data.0.user_id'));
    }

    public function test_polling_the_roster_refreshes_the_callers_own_last_seen_at(): void
    {
        $organizer = $this->activeUser();
        $meetingId = $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Heartbeat refresh'])->json('data.id');
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");

        \Modules\Meetings\Models\MeetingParticipant::where('meeting_id', $meetingId)
            ->where('user_id', $organizer->id)
            ->update(['last_seen_at' => now()->subMinutes(10)]);

        $this->actingAs($organizer, 'sanctum')->getJson("/api/v1/meetings/{$meetingId}/participants")->assertStatus(200);

        $participant = \Modules\Meetings\Models\MeetingParticipant::where('meeting_id', $meetingId)->where('user_id', $organizer->id)->first();
        $this->assertTrue($participant->last_seen_at->greaterThan(now()->subSeconds(5)));
    }
}
