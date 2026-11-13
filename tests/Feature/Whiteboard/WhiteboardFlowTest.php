<?php

namespace Tests\Feature\Whiteboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Meetings\Models\Meeting;
use Tests\TestCase;

class WhiteboardFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(): User
    {
        return User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    }

    private function startMeeting(User $organizer): int
    {
        $meetingId = $this->actingAs($organizer, 'sanctum')
            ->postJson('/api/v1/meetings/instant', ['title' => 'Design review'])
            ->json('data.id');

        // The organizer is no longer auto-joined at creation (see
        // MeetingService::createInstant()'s docblock) - every
        // whiteboard test in this file needs them to already be an
        // active participant.
        $this->actingAs($organizer, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");

        return $meetingId;
    }

    private function joinAsGuest(int $meetingId, string $name): string
    {
        Meeting::where('id', $meetingId)->update(['waiting_room_enabled' => false]);

        return $this->postJson("/api/v1/meetings/{$meetingId}/guest", ['guest_name' => $name])
            ->json('data.guest_session.session_token');
    }

    public function test_opening_a_whiteboard_is_idempotent_and_seeds_one_page(): void
    {
        $host = $this->activeUser();
        $meetingId = $this->startMeeting($host);

        $first = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/whiteboard");
        $first->assertStatus(201);
        $whiteboardId = $first->json('data.id');
        $this->assertCount(1, $first->json('data.pages'));

        $second = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/whiteboard");
        $this->assertSame($whiteboardId, $second->json('data.id'));
    }

    public function test_drawing_an_object_is_visible_to_another_participant_via_polling(): void
    {
        $host = $this->activeUser();
        $member = $this->activeUser();
        $meetingId = $this->startMeeting($host);
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");

        $whiteboard = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/whiteboard")->json('data');
        $pageId = $whiteboard['pages'][0]['id'];

        $create = $this->actingAs($host, 'sanctum')->postJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects", [
            'tool' => 'pen',
            'data' => ['points' => [[0, 0], [10, 10]]],
            'color' => '#ff0000',
            'thickness' => 2,
        ]);
        $create->assertStatus(201);
        $objectId = $create->json('data.id');

        $poll = $this->actingAs($member, 'sanctum')->getJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects?after_id=0");
        $poll->assertStatus(200);
        $this->assertCount(1, $poll->json('data.created'));
        $this->assertSame($objectId, $poll->json('data.created.0.id'));
    }

    public function test_view_only_permission_blocks_drawing(): void
    {
        $host = $this->activeUser();
        $member = $this->activeUser();
        $meetingId = $this->startMeeting($host);
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/join");

        $whiteboard = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/whiteboard")->json('data');
        $this->actingAs($host, 'sanctum')->patchJson("/api/v1/whiteboards/{$whiteboard['id']}/permission", ['permission_mode' => 'view_only'])->assertStatus(200);

        $pageId = $whiteboard['pages'][0]['id'];
        $this->actingAs($member, 'sanctum')->postJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects", [
            'tool' => 'pen',
            'data' => ['points' => [[0, 0], [5, 5]]],
        ])->assertStatus(403);
    }

    public function test_undo_and_redo_toggle_object_visibility(): void
    {
        $host = $this->activeUser();
        $meetingId = $this->startMeeting($host);
        $whiteboard = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/whiteboard")->json('data');
        $pageId = $whiteboard['pages'][0]['id'];

        $object = $this->actingAs($host, 'sanctum')->postJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects", [
            'tool' => 'rectangle',
            'data' => ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5],
        ])->json('data');

        $this->assertDatabaseHas('whiteboard_objects', ['id' => $object['id'], 'is_deleted' => false]);

        $this->actingAs($host, 'sanctum')->postJson("/api/v1/whiteboards/{$whiteboard['id']}/undo")->assertStatus(200);
        $this->assertDatabaseHas('whiteboard_objects', ['id' => $object['id'], 'is_deleted' => true]);

        $this->actingAs($host, 'sanctum')->postJson("/api/v1/whiteboards/{$whiteboard['id']}/redo")->assertStatus(200);
        $this->assertDatabaseHas('whiteboard_objects', ['id' => $object['id'], 'is_deleted' => false]);
    }

    public function test_clear_page_soft_deletes_every_object(): void
    {
        $host = $this->activeUser();
        $meetingId = $this->startMeeting($host);
        $whiteboard = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/whiteboard")->json('data');
        $pageId = $whiteboard['pages'][0]['id'];

        $this->actingAs($host, 'sanctum')->postJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects", ['tool' => 'pen', 'data' => ['points' => [[0, 0], [1, 1]]]]);
        $this->actingAs($host, 'sanctum')->postJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects", ['tool' => 'rectangle', 'data' => ['x' => 0, 'y' => 0, 'width' => 2, 'height' => 2]]);

        $this->actingAs($host, 'sanctum')->postJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/clear")->assertStatus(200);

        $poll = $this->actingAs($host, 'sanctum')->getJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects?after_id=0");
        $this->assertCount(0, $poll->json('data.created'));
    }

    public function test_pages_can_be_added_and_deleted_but_not_below_one(): void
    {
        $host = $this->activeUser();
        $meetingId = $this->startMeeting($host);
        $whiteboard = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/whiteboard")->json('data');
        $firstPageId = $whiteboard['pages'][0]['id'];

        $secondPage = $this->actingAs($host, 'sanctum')->postJson("/api/v1/whiteboards/{$whiteboard['id']}/pages", ['name' => 'Page 2']);
        $secondPage->assertStatus(201)->assertJsonPath('data.page_number', 2);

        $this->actingAs($host, 'sanctum')->deleteJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$secondPage->json('data.id')}")->assertStatus(200);

        // Cannot delete the last remaining page.
        $this->actingAs($host, 'sanctum')->deleteJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$firstPageId}")->assertStatus(422);
    }

    public function test_a_stranger_cannot_touch_a_meetings_whiteboard(): void
    {
        $host = $this->activeUser();
        $stranger = $this->activeUser();
        $meetingId = $this->startMeeting($host);
        $whiteboard = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/whiteboard")->json('data');

        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/whiteboards/{$whiteboard['id']}")->assertStatus(403);
    }

    public function test_a_guest_can_open_a_whiteboard_and_draw_on_it_without_any_account(): void
    {
        $host = $this->activeUser();
        $meetingId = $this->startMeeting($host);
        $token = $this->joinAsGuest($meetingId, 'Visiting Vic');

        $whiteboard = $this->postJson("/api/v1/meetings/guest/{$token}/whiteboard")->assertStatus(201)->json('data');
        $pageId = $whiteboard['pages'][0]['id'];

        $object = $this->postJson("/api/v1/meetings/guest/{$token}/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects", [
            'tool' => 'pen',
            'data' => ['points' => [[0, 0], [10, 10]]],
            'color' => '#00ff00',
            'thickness' => 3,
        ])->assertStatus(201)->json('data');

        $this->assertSame('Visiting Vic (Guest)', $object['creator_name']);
        $this->assertNull($object['created_by']);
        $this->assertNotNull($object['created_by_participant_id']);

        $this->assertDatabaseHas('whiteboard_objects', [
            'id' => $object['id'],
            'created_by' => null,
            'created_by_participant_id' => $object['created_by_participant_id'],
        ]);

        // The registered host polling the same page sees the guest's stroke.
        $poll = $this->actingAs($host, 'sanctum')->getJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects?after_id=0");
        $poll->assertStatus(200);
        $this->assertCount(1, $poll->json('data.created'));
        $this->assertSame('Visiting Vic (Guest)', $poll->json('data.created.0.creator_name'));
    }

    public function test_a_registered_hosts_stroke_and_a_guests_stroke_coexist_on_the_same_page(): void
    {
        $host = $this->activeUser();
        $meetingId = $this->startMeeting($host);
        $token = $this->joinAsGuest($meetingId, 'Co-drawing Casey');

        $whiteboard = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/whiteboard")->json('data');
        $pageId = $whiteboard['pages'][0]['id'];

        $hostObject = $this->actingAs($host, 'sanctum')->postJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects", [
            'tool' => 'rectangle',
            'data' => ['x' => 0, 'y' => 0, 'width' => 5, 'height' => 5],
        ])->json('data');
        $this->assertSame($host->full_name, $hostObject['creator_name']);

        $guestObject = $this->postJson("/api/v1/meetings/guest/{$token}/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects", [
            'tool' => 'pen',
            'data' => ['points' => [[1, 1], [2, 2]]],
        ])->json('data');
        $this->assertSame('Co-drawing Casey (Guest)', $guestObject['creator_name']);

        $poll = $this->actingAs($host, 'sanctum')->getJson("/api/v1/whiteboards/{$whiteboard['id']}/pages/{$pageId}/objects?after_id=0");
        $this->assertCount(2, $poll->json('data.created'));
    }

    public function test_a_guest_from_a_different_meeting_cannot_touch_this_meetings_whiteboard(): void
    {
        $host = $this->activeUser();
        $meetingId = $this->startMeeting($host);
        $whiteboard = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$meetingId}/whiteboard")->json('data');

        $otherHost = $this->activeUser();
        $otherMeetingId = $this->startMeeting($otherHost);
        $otherToken = $this->joinAsGuest($otherMeetingId, 'Wrong Meeting Wanda');

        $this->getJson("/api/v1/meetings/guest/{$otherToken}/whiteboards/{$whiteboard['id']}")->assertStatus(403);
    }

    public function test_a_guest_can_change_whiteboard_permission_when_they_hold_the_host_role(): void
    {
        $host = $this->activeUser();
        $meetingId = $this->startMeeting($host);
        $token = $this->joinAsGuest($meetingId, 'Promoted Guest');

        // Simulates a guest who created the meeting themselves (a real
        // "no-account" flow that goes through a web form, not this
        // JSON API - see meetings.test.js) by directly granting the
        // host role to this guest's own participant row, which is the
        // one thing that flow actually does differently from a normal
        // guest join.
        \Modules\Meetings\Models\MeetingParticipant::where('guest_session_id',
            \Modules\Meetings\Models\MeetingGuestSession::where('session_token', $token)->first()->id
        )->update(['role' => 'host']);

        $whiteboard = $this->postJson("/api/v1/meetings/guest/{$token}/whiteboard")->assertStatus(201)->json('data');

        $this->patchJson("/api/v1/meetings/guest/{$token}/whiteboards/{$whiteboard['id']}/permission", ['permission_mode' => 'view_only'])
            ->assertStatus(200)
            ->assertJsonPath('data.permission_mode', 'view_only');
    }
}
