<?php

namespace Tests\Feature\Meetings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingParticipant;
use Modules\Meetings\Services\MeetingSummarizer;
use Tests\TestCase;

/** Live captions, recording, breakout rooms and the meeting summary. */
class MeetingExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name): User
    {
        return User::factory()->create(['full_name' => $name, 'status' => 'active', 'email_verified_at' => now()]);
    }

    private function meetingWith(User $host, User ...$others): Meeting
    {
        $id = $this->actingAs($host, 'sanctum')->postJson('/api/v1/meetings/instant', ['title' => 'Weekly sales'])->json('data.id');
        $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$id}/join")->assertOk();
        foreach ($others as $u) {
            $this->actingAs($u, 'sanctum')->postJson("/api/v1/meetings/{$id}/join")->assertOk();
        }

        return Meeting::findOrFail($id);
    }

    private function pid(Meeting $m, User $u): int
    {
        return (int) MeetingParticipant::where('meeting_id', $m->id)->where('user_id', $u->id)->value('id');
    }

    public function test_captions_reach_everybody_and_make_the_summary(): void
    {
        $host = $this->user('Asha');
        $ravi = $this->user('Ravi');
        $m = $this->meetingWith($host, $ravi);
        $outsider = $this->user('Outsider');

        $this->actingAs($ravi, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/captions", ['text' => 'We close the Pune order by Friday.'])->assertCreated();
        $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/captions", ['text' => '  Ravi sends   the quote today. '])->assertCreated();
        $ev = $this->actingAs($host, 'sanctum')->getJson("/api/v1/meetings/{$m->id}/events?after=0")->assertOk()->json('data.events');
        $this->assertSame(['Ravi', 'We close the Pune order by Friday.'], [$ev[0]['payload']['name'], $ev[0]['payload']['text']]);
        $this->assertSame('Ravi sends the quote today.', $ev[1]['payload']['text']);
        $after = $ev[0]['id'];
        $this->assertCount(1, $this->actingAs($host, 'sanctum')->getJson("/api/v1/meetings/{$m->id}/events?after={$after}")->json('data.events'));
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/meetings/{$m->id}/events")->assertForbidden();

        // summary by the AI writer, with the transcript of the captions
        $this->app->bind(MeetingSummarizer::class, fn () => new class implements MeetingSummarizer {
            public function summarize(string $title, string $transcript): string
            {
                return "Summary of {$title}\n" . $transcript;
            }
        });
        $s = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/summary")->assertOk()->json('data');
        $this->assertTrue($s['ai']);
        $this->assertStringContainsString("Ravi: We close the Pune order by Friday.\nAsha: Ravi sends the quote today.", $s['summary']);
        $this->actingAs($ravi, 'sanctum')->getJson("/api/v1/meetings/{$m->id}/summary")->assertOk()->assertJsonPath('data.summary', $s['summary']);
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/meetings/{$m->id}/summary")->assertForbidden();

        // no AI key: a plain summary instead of an error
        $this->app->bind(MeetingSummarizer::class, fn () => new class implements MeetingSummarizer {
            public function summarize(string $title, string $transcript): string
            {
                throw new \RuntimeException('No AI key is set (MEET_AI_KEY).');
            }
        });
        [$text, $ai] = app(\Modules\Meetings\Services\MeetingExtrasService::class)->summarize($m, $host);
        $this->assertFalse($ai);
        $this->assertStringContainsString('Who spoke: Ravi (1), Asha (1)', $text);
    }

    public function test_only_the_host_records_and_sees_recordings(): void
    {
        Storage::fake('local');
        $host = $this->user('Host');
        $guest = $this->user('Member');
        $m = $this->meetingWith($host, $guest);

        $this->actingAs($guest, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/recording", ['on' => true])->assertForbidden();
        $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/recording", ['on' => true])->assertOk();
        $this->actingAs($guest, 'sanctum')->getJson("/api/v1/meetings/{$m->id}/events")->assertJsonPath('data.recording', true);

        $file = UploadedFile::fake()->create('meeting.webm', 200, 'video/webm');
        $this->actingAs($guest, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/recordings", ['file' => $file, 'seconds' => 60])->assertForbidden();
        $id = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/recordings", ['file' => $file, 'seconds' => 60])->assertCreated()->json('data.id');
        $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/recording", ['on' => false])->assertOk();
        $this->actingAs($guest, 'sanctum')->getJson("/api/v1/meetings/{$m->id}/events")->assertJsonPath('data.recording', false);

        $this->actingAs($host, 'sanctum')->getJson("/api/v1/meetings/{$m->id}/recordings")->assertOk()->assertJsonPath('data.0.seconds', 60);
        $this->actingAs($host, 'sanctum')->get("/api/v1/meetings/{$m->id}/recordings/{$id}")->assertOk();
        $this->actingAs($guest, 'sanctum')->getJson("/api/v1/meetings/{$m->id}/recordings")->assertForbidden();
        $this->actingAs($guest, 'sanctum')->get("/api/v1/meetings/{$m->id}/recordings/{$id}")->assertNotFound();
    }

    public function test_breakout_rooms_send_people_away_and_back(): void
    {
        $host = $this->user('Host');
        $a = $this->user('A');
        $b = $this->user('B');
        $m = $this->meetingWith($host, $a, $b);

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/breakouts", ['rooms' => [['name' => 'One', 'participants' => [$this->pid($m, $b)]]]])->assertForbidden();
        $rooms = $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/breakouts", ['rooms' => [
            ['name' => 'North', 'participants' => [$this->pid($m, $a)]], ['name' => 'South', 'participants' => [$this->pid($m, $b), $this->pid($m, $host)]],
        ]])->assertCreated()->json('data');
        $this->assertSame(['North', 'South'], array_column($rooms, 'name'));
        $ev = collect($this->actingAs($a, 'sanctum')->getJson("/api/v1/meetings/{$m->id}/events")->json('data.events'))->firstWhere('type', 'breakout');
        $this->assertSame($rooms[0]['id'], $ev['payload']['assign'][(string) $this->pid($m, $a)]);
        $this->assertArrayNotHasKey((string) $this->pid($m, $host), $ev['payload']['assign']); // the host stays in the main room
        $this->assertSame($m->id, Meeting::find($rooms[1]['id'])->parent_meeting_id);

        // A goes to the room, speaks there; the summary of the main meeting includes it
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/meetings/{$rooms[0]['id']}/join")->assertOk();
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/meetings/{$rooms[0]['id']}/captions", ['text' => 'North room idea'])->assertCreated();

        $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/breakouts/close")->assertOk()->assertJsonPath('data.closed', 2);
        $back = collect($this->actingAs($a, 'sanctum')->getJson("/api/v1/meetings/{$rooms[0]['id']}/events")->json('data.events'))->firstWhere('type', 'return');
        $this->assertSame($m->id, $back['payload']['meeting_id']);
        $this->assertSame('ended', Meeting::find($rooms[0]['id'])->status);
        $this->assertStringContainsString('A: North room idea', app(\Modules\Meetings\Services\MeetingExtrasService::class)->transcript($m));
    }

    public function test_guests_send_and_see_captions(): void
    {
        $host = $this->user('Host');
        $m = $this->meetingWith($host);
        $token = $this->postJson("/api/v1/meetings/{$m->id}/guest", ['guest_name' => 'Walk-in Wendy'])->json('data.guest_session.session_token');
        $this->assertNotEmpty($token);
        $this->postJson("/api/v1/meetings/guest/{$token}/captions", ['text' => 'Hello from the guest'])->assertCreated();
        $this->actingAs($host, 'sanctum')->postJson("/api/v1/meetings/{$m->id}/captions", ['text' => 'Welcome'])->assertCreated();
        $ev = $this->getJson("/api/v1/meetings/guest/{$token}/events")->assertOk()->json('data.events');
        $this->assertSame([['Walk-in Wendy', 'Hello from the guest'], ['Host', 'Welcome']], array_map(fn ($e) => [$e['payload']['name'], $e['payload']['text']], $ev));
        $this->getJson('/api/v1/meetings/guest/not-a-token/events')->assertNotFound();
        $this->get("/meetings/guest/{$token}")->assertOk()->assertSee('mx-cc-btn', false);
    }
}
