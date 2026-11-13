<?php

namespace Tests\Feature\Meetings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Meetings\Jobs\EndInactiveMeetingsJob;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingParticipant;
use Tests\TestCase;

class EndInactiveMeetingsJobTest extends TestCase
{
    use RefreshDatabase;

    private function meeting(array $overrides = []): Meeting
    {
        $organizer = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        $meeting = Meeting::create(array_merge([
            'organizer_id' => $organizer->id,
            'meeting_code' => (string) random_int(100000000, 999999999),
            'title' => 'Test meeting',
            'meeting_type' => 'instant',
            'status' => 'active',
            'actual_start' => now(),
        ], $overrides));

        if (isset($overrides['created_at'])) {
            $meeting->forceFill(['created_at' => $overrides['created_at']])->save();
        }

        return $meeting;
    }

    /**
     * The most common case this closes: an organizer creates an
     * instant meeting (which no longer auto-joins them - see
     * MeetingService::createInstant()'s docblock) and then abandons
     * the lobby without ever clicking "Join meeting". Nothing else
     * would ever end a meeting nobody actually entered.
     */
    public function test_a_meeting_nobody_ever_joined_is_ended_after_the_grace_window(): void
    {
        $meeting = $this->meeting(['created_at' => now()->subMinutes(6)]);

        (new EndInactiveMeetingsJob)->handle(app(\Modules\Meetings\Repositories\MeetingRepository::class));

        $this->assertSame('ended', $meeting->fresh()->status);
        $this->assertNotNull($meeting->fresh()->actual_end);
    }

    public function test_a_meeting_created_less_than_5_minutes_ago_with_nobody_in_it_yet_is_left_alone(): void
    {
        $meeting = $this->meeting(['created_at' => now()->subMinutes(2)]);

        (new EndInactiveMeetingsJob)->handle(app(\Modules\Meetings\Repositories\MeetingRepository::class));

        $this->assertSame('active', $meeting->fresh()->status);
    }

    public function test_a_meeting_with_a_currently_live_participant_is_never_ended(): void
    {
        $meeting = $this->meeting(['created_at' => now()->subMinutes(10)]);
        $participant = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => $participant->id,
            'role' => MeetingParticipant::PARTICIPANT,
            'joined_at' => now()->subMinutes(9),
            'last_seen_at' => now(),
        ]);

        (new EndInactiveMeetingsJob)->handle(app(\Modules\Meetings\Repositories\MeetingRepository::class));

        $this->assertSame('active', $meeting->fresh()->status);
    }

    public function test_a_meeting_everyone_left_more_than_5_minutes_ago_is_ended(): void
    {
        $meeting = $this->meeting(['created_at' => now()->subMinutes(20)]);
        $participant = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => $participant->id,
            'role' => MeetingParticipant::PARTICIPANT,
            'joined_at' => now()->subMinutes(19),
            'left_at' => now()->subMinutes(6),
        ]);

        (new EndInactiveMeetingsJob)->handle(app(\Modules\Meetings\Repositories\MeetingRepository::class));

        $this->assertSame('ended', $meeting->fresh()->status);
    }

    public function test_a_meeting_someone_left_within_the_last_5_minutes_gets_a_grace_window(): void
    {
        $meeting = $this->meeting(['created_at' => now()->subMinutes(20)]);
        $participant = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => $participant->id,
            'role' => MeetingParticipant::PARTICIPANT,
            'joined_at' => now()->subMinutes(19),
            'left_at' => now()->subMinute(),
        ]);

        (new EndInactiveMeetingsJob)->handle(app(\Modules\Meetings\Repositories\MeetingRepository::class));

        $this->assertSame('active', $meeting->fresh()->status);
    }

    public function test_a_scheduled_meeting_that_has_not_started_is_never_touched(): void
    {
        $meeting = $this->meeting([
            'status' => 'scheduled',
            'meeting_type' => 'scheduled',
            'actual_start' => null,
            'scheduled_start' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addHour(),
            'timezone' => 'UTC',
            'created_at' => now()->subMinutes(20),
        ]);

        (new EndInactiveMeetingsJob)->handle(app(\Modules\Meetings\Repositories\MeetingRepository::class));

        $this->assertSame('scheduled', $meeting->fresh()->status);
    }

    public function test_an_already_ended_meeting_is_left_alone(): void
    {
        $meeting = $this->meeting(['status' => 'ended', 'actual_end' => now()->subMinutes(10), 'created_at' => now()->subMinutes(20)]);

        (new EndInactiveMeetingsJob)->handle(app(\Modules\Meetings\Repositories\MeetingRepository::class));

        $this->assertSame('ended', $meeting->fresh()->status);
    }
}
