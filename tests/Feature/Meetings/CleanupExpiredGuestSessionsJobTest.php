<?php

namespace Tests\Feature\Meetings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Meetings\Jobs\CleanupExpiredGuestSessionsJob;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingGuestSession;
use Modules\Meetings\Models\MeetingParticipant;
use Tests\TestCase;

class CleanupExpiredGuestSessionsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_guest_sessions_are_marked_expired_and_their_participant_leaves(): void
    {
        $organizer = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $meeting = Meeting::create([
            'organizer_id' => $organizer->id,
            'meeting_code' => '123456789',
            'title' => 'Org meeting',
            'meeting_type' => 'instant',
            'status' => 'active',
            'actual_start' => now(),
        ]);

        $session = MeetingGuestSession::create([
            'meeting_id' => $meeting->id,
            'guest_name' => 'Guest',
            'session_token' => 'tok',
            'joined_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinute(),
            'status' => 'active',
        ]);

        $participant = MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'guest_session_id' => $session->id,
            'role' => MeetingParticipant::PARTICIPANT,
            'joined_at' => now()->subMinutes(20),
        ]);

        (new CleanupExpiredGuestSessionsJob)->handle();

        $this->assertSame('expired', $session->fresh()->status);
        $this->assertNotNull($participant->fresh()->left_at);

        // Has a real organizer - must never be auto-ended by this job.
        $this->assertSame('active', $meeting->fresh()->status);
    }

    public function test_guest_created_meeting_is_auto_ended_once_its_only_guest_session_expires(): void
    {
        $meeting = Meeting::create([
            'organizer_id' => null,
            'meeting_code' => '987654321',
            'title' => 'Guest meeting',
            'meeting_type' => 'instant',
            'status' => 'active',
            'actual_start' => now(),
        ]);

        $hostSession = MeetingGuestSession::create([
            'meeting_id' => $meeting->id,
            'guest_name' => 'Host Guest',
            'session_token' => 'host-tok',
            'joined_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinute(),
            'status' => 'active',
        ]);

        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'guest_session_id' => $hostSession->id,
            'role' => MeetingParticipant::HOST,
            'joined_at' => now()->subMinutes(20),
        ]);

        (new CleanupExpiredGuestSessionsJob)->handle();

        $this->assertSame('ended', $meeting->fresh()->status);
        $this->assertNotNull($meeting->fresh()->actual_end);
    }

    public function test_guest_created_meeting_is_not_ended_while_another_guest_session_is_still_active(): void
    {
        $meeting = Meeting::create([
            'organizer_id' => null,
            'meeting_code' => '111222333',
            'title' => 'Guest meeting with two guests',
            'meeting_type' => 'instant',
            'status' => 'active',
            'actual_start' => now(),
        ]);

        $expiredSession = MeetingGuestSession::create([
            'meeting_id' => $meeting->id,
            'guest_name' => 'Host Guest',
            'session_token' => 'host-tok-2',
            'joined_at' => now()->subMinutes(20),
            'expires_at' => now()->subMinute(),
            'status' => 'active',
        ]);
        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'guest_session_id' => $expiredSession->id,
            'role' => MeetingParticipant::HOST,
            'joined_at' => now()->subMinutes(20),
        ]);

        $stillActiveSession = MeetingGuestSession::create([
            'meeting_id' => $meeting->id,
            'guest_name' => 'Other Guest',
            'session_token' => 'other-tok',
            'joined_at' => now(),
            'expires_at' => now()->addMinutes(10),
            'status' => 'active',
        ]);
        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'guest_session_id' => $stillActiveSession->id,
            'role' => MeetingParticipant::PARTICIPANT,
            'joined_at' => now(),
        ]);

        (new CleanupExpiredGuestSessionsJob)->handle();

        $this->assertSame('active', $meeting->fresh()->status, 'should stay active while another guest session is still active');
    }
}
