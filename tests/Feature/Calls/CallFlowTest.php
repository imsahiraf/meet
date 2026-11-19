<?php

namespace Tests\Feature\Calls;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Calls\Jobs\MarkMissedCallsJob;
use Modules\Calls\Models\Call;
use Modules\Calls\Services\CallService;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationMember;
use Modules\Calls\Repositories\CallRepository;
use Tests\TestCase;

class CallFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_start_a_voice_call_and_accept_it(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();

        $start = $this->actingAs($a, 'sanctum')->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id]);
        $start->assertStatus(201)->assertJsonPath('data.call_status', 'ringing')->assertJsonPath('data.call_type', 'voice');
        $callId = $start->json('data.id');

        $this->actingAs($b, 'sanctum')
            ->getJson('/api/v1/calls/incoming')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $callId);

        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/accept")
            ->assertStatus(200)
            ->assertJsonPath('data.call_status', 'accepted');

        $this->assertNotNull(Call::find($callId)->answered_at);
    }

    public function test_receiver_rejects_a_call(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/video/start', ['receiver_id' => $b->id])
            ->json('data.id');

        $this->actingAs($b, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/reject")
            ->assertStatus(200)
            ->assertJsonPath('data.call_status', 'rejected');

        $this->assertDatabaseHas('call_history', ['call_id' => $callId, 'end_reason' => 'rejected']);
    }

    public function test_caller_cancels_before_receiver_answers(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/end")
            ->assertStatus(200)
            ->assertJsonPath('data.call_status', 'cancelled');

        // Receiver may not cancel a ringing call - only reject it.
        $callId2 = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId2}/end")->assertStatus(403);
    }

    public function test_ending_an_accepted_call_records_duration_and_history(): void
    {
        $this->freezeSecond(); // the clock must not move by itself between accept and end (the duration was 46 on a slow run)
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");

        $this->travel(45)->seconds();

        $end = $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/end");
        $end->assertStatus(200)->assertJsonPath('data.call_status', 'ended');
        $this->assertSame(45, $end->json('data.duration_seconds'));

        $this->assertDatabaseHas('call_history', ['call_id' => $callId, 'end_reason' => 'completed', 'duration_seconds' => 45]);
    }

    public function test_cannot_start_a_call_while_already_on_one(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $c = $this->activeUser();

        $this->actingAs($a, 'sanctum')->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])->assertStatus(201);

        // A is now busy (still ringing) - cannot start a second call.
        $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $c->id])
            ->assertStatus(409);

        // Someone else calling B (who is being rung by A) also gets busy.
        $this->actingAs($c, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->assertStatus(409);
    }

    public function test_only_participants_can_act_on_a_call(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $stranger = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');

        $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept")->assertStatus(403);
        $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/calls/{$callId}")->assertStatus(403);
    }

    /**
     * Real mute/camera-off signal to the other party - previously a
     * purely local UI toggle the other side never saw at all.
     */
    public function test_media_state_reports_the_other_partys_mute_and_camera_state(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/video/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");

        // Before anyone touches a control, both default to "on".
        $this->actingAs($b, 'sanctum')->getJson("/api/v1/calls/{$callId}")
            ->assertJsonPath('data.other_party_media_state.audio_enabled', true)
            ->assertJsonPath('data.other_party_media_state.video_enabled', true);

        $this->actingAs($a, 'sanctum')
            ->patchJson("/api/v1/calls/{$callId}/media", ['audio_enabled' => false])
            ->assertStatus(200);

        // B sees A's real state; A's OWN media_state is irrelevant to
        // what A itself is told (it already knows its own local state).
        $this->actingAs($b, 'sanctum')->getJson("/api/v1/calls/{$callId}")
            ->assertJsonPath('data.other_party_media_state.audio_enabled', false)
            ->assertJsonPath('data.other_party_media_state.video_enabled', true);

        // Setting only video_enabled must not clobber the audio_enabled
        // value already recorded for that same side.
        $this->actingAs($a, 'sanctum')
            ->patchJson("/api/v1/calls/{$callId}/media", ['video_enabled' => false])
            ->assertStatus(200);

        $this->actingAs($b, 'sanctum')->getJson("/api/v1/calls/{$callId}")
            ->assertJsonPath('data.other_party_media_state.audio_enabled', false)
            ->assertJsonPath('data.other_party_media_state.video_enabled', false);
    }

    /**
     * A quick emoji reaction during a call rides the same signal
     * channel offer/answer/ice already use.
     */
    public function test_a_reaction_is_delivered_to_the_other_party_via_the_signal_poll(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/video/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/reaction", ['emoji' => '👍'])
            ->assertStatus(201);

        $poll = $this->actingAs($b, 'sanctum')
            ->getJson("/api/v1/calls/{$callId}/signals")
            ->assertStatus(200);

        $reactionSignal = collect($poll->json('data.signals'))->firstWhere('signal_type', 'reaction');
        $this->assertNotNull($reactionSignal, 'expected a reaction signal to be delivered to the other party');
        $this->assertSame('👍', $reactionSignal['payload']);

        // The sender never sees their own reaction come back through
        // the poll (it's a signal TO the other party, not a broadcast).
        $selfPoll = $this->actingAs($a, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals");
        $this->assertNull(collect($selfPoll->json('data.signals'))->firstWhere('signal_type', 'reaction'));
    }

    /**
     * A voice call's video transceiver is pre-provisioned with no
     * track from the start (see room-script's setupPeerConnection
     * docblock) specifically so starting/stopping screen share never
     * needs SDP renegotiation - the receiving side has no other
     * reliable way to tell "camera turned on" apart from "screen
     * share started", or when it stops, without this explicit signal.
     */
    public function test_a_screen_share_signal_is_delivered_to_the_other_party(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/screen-share", ['state' => 'started'])
            ->assertStatus(201);

        $poll = $this->actingAs($b, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals")->assertStatus(200);
        $signal = collect($poll->json('data.signals'))->firstWhere('signal_type', 'screen-share');
        $this->assertNotNull($signal, 'expected a screen-share signal to be delivered to the other party');
        $this->assertSame('started', $signal['payload']);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/screen-share", ['state' => 'bogus'])
            ->assertStatus(422);
    }

    /**
     * Offer/answer/ICE are per-PAIR now (an explicit receiver_id, the
     * same shape MeetingSignalingController uses for mesh signaling) -
     * for a 1:1 call that pair is just the only pair there is.
     */
    public function test_signaling_offer_answer_and_ice_are_delivered_to_the_other_party_only(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/offer", ['receiver_id' => $b->id, 'sdp' => 'fake-offer-sdp'])->assertStatus(201);
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/ice", ['receiver_id' => $b->id, 'candidate' => 'fake-ice-1'])->assertStatus(201);

        $poll = $this->actingAs($b, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals?after_signal_id=0&after_ice_id=0");
        $poll->assertStatus(200);
        $this->assertCount(1, $poll->json('data.signals'));
        $this->assertSame('fake-offer-sdp', $poll->json('data.signals.0.payload'));
        $this->assertCount(1, $poll->json('data.ice_candidates'));

        // A polling their own outbound signals sees nothing (they were the sender, not the receiver).
        $selfPoll = $this->actingAs($a, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals?after_signal_id=0&after_ice_id=0");
        $this->assertCount(0, $selfPoll->json('data.signals'));

        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/answer", ['receiver_id' => $a->id, 'sdp' => 'fake-answer-sdp'])->assertStatus(201);
        $answerPoll = $this->actingAs($a, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals?after_signal_id=0&after_ice_id=0");
        $this->assertCount(1, $answerPoll->json('data.signals'));
        $this->assertSame('answer', $answerPoll->json('data.signals.0.signal_type'));

        // Re-polling with an after cursor returns nothing new.
        $lastSignalId = $poll->json('data.signals.0.id');
        $lastIceId = $poll->json('data.ice_candidates.0.id');
        $emptyPoll = $this->actingAs($b, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals?after_signal_id={$lastSignalId}&after_ice_id={$lastIceId}");
        $this->assertCount(0, $emptyPoll->json('data.signals'));
        $this->assertCount(0, $emptyPoll->json('data.ice_candidates'));
    }

    public function test_call_history_lists_completed_calls_for_both_parties(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/end")->assertStatus(200);

        $this->actingAs($a, 'sanctum')->getJson('/api/v1/calls/history')->assertStatus(200)->assertJsonCount(1, 'data');
        $this->actingAs($b, 'sanctum')->getJson('/api/v1/calls/history')->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_mark_missed_calls_job_expires_stale_ringing_calls(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');

        $this->travel(31)->seconds();
        (new MarkMissedCallsJob())->handle(app(CallRepository::class), app(CallService::class), app(\Modules\Core\Services\PushNotificationService::class));

        $this->assertSame('missed', Call::find($callId)->call_status);
        $this->assertDatabaseHas('call_history', ['call_id' => $callId, 'end_reason' => 'timeout']);
    }

    /**
     * DB hygiene: an ACCEPTED call has no timeout of its own, so if every
     * participant's browser simply disappears mid-call, nothing ever
     * calls /end. Those rows matter now that call_participants is what
     * the busy check and the incoming-call poll both read - left alone,
     * both people would be permanently "on a call".
     */
    public function test_the_cron_ends_an_accepted_call_nobody_ever_hung_up(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");

        // Well within the window: still a perfectly normal live call.
        $this->travel(60)->minutes();
        $this->runCallCron();
        $this->assertSame('accepted', Call::find($callId)->call_status);

        $this->travel(13)->hours();
        $this->runCallCron();

        $this->assertSame('ended', Call::find($callId)->call_status);
        $this->assertDatabaseHas('call_history', ['call_id' => $callId, 'end_reason' => 'abandoned']);

        // Both are free to call again - nobody is left marked present.
        $this->assertSame(0, \Modules\Calls\Models\CallParticipant::where('call_id', $callId)->whereNull('left_at')->count());
        $this->actingAs($a, 'sanctum')->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])->assertStatus(201);
    }

    /**
     * The incoming poll exists to catch "somebody just added me to a
     * call", so it must not hand back a call the user was added to ages
     * ago - otherwise a single stale row would drag them into a ghost
     * call on every page they open.
     */
    public function test_the_incoming_poll_ignores_a_call_you_were_added_to_long_ago(): void
    {
        [, , $c, $callId] = $this->threeWayCall();

        $this->actingAs($c, 'sanctum')->getJson('/api/v1/calls/incoming')
            ->assertJsonPath('data.id', $callId);

        $this->travel(5)->minutes();

        $this->actingAs($c, 'sanctum')->getJson('/api/v1/calls/incoming')
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    private function runCallCron(): void
    {
        (new MarkMissedCallsJob())->handle(
            app(CallRepository::class),
            app(CallService::class),
            app(\Modules\Core\Services\PushNotificationService::class),
        );
    }

    private function privateConversationBetween(User $a, User $b): Conversation
    {
        $conversation = Conversation::create(['type' => 'private', 'created_by' => $a->id, 'last_activity_at' => now()]);
        ConversationMember::create(['conversation_id' => $conversation->id, 'user_id' => $a->id, 'role' => 'owner']);
        ConversationMember::create(['conversation_id' => $conversation->id, 'user_id' => $b->id, 'role' => 'member']);

        return $conversation;
    }

    /**
     * WhatsApp-style: a call started from a chat gets logged as a row
     * in that chat's own timeline once it ends - "Voice call · 0:45"
     * for one that connected and ran 45 seconds.
     */
    public function test_a_completed_call_with_a_conversation_id_logs_it_in_that_conversations_timeline(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversation = $this->privateConversationBetween($a, $b);

        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id, 'conversation_id' => $conversation->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");
        $this->travel(45)->seconds();
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/end")->assertStatus(200);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'message_type' => 'system',
            'body' => 'Voice call · 00:45',
        ]);
    }

    public function test_a_rejected_call_logs_a_declined_call_message(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversation = $this->privateConversationBetween($a, $b);

        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/video/start', ['receiver_id' => $b->id, 'conversation_id' => $conversation->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/reject")->assertStatus(200);

        // A receiver explicitly declining is a distinct outcome from a
        // true no-answer timeout (see test_mark_missed_calls_job_...
        // below) - both used to render as the same "Missed" wording.
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'message_type' => 'system',
            'body' => 'Declined video call',
        ]);
    }

    public function test_a_cancelled_call_logs_a_cancelled_call_message(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversation = $this->privateConversationBetween($a, $b);

        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id, 'conversation_id' => $conversation->id])
            ->json('data.id');
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/end")->assertStatus(200);

        // The caller hanging up before pickup is distinct from a
        // receiver-side decline or a true no-answer timeout.
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'message_type' => 'system',
            'body' => 'Voice call cancelled',
        ]);
    }

    public function test_mark_missed_calls_job_also_logs_a_missed_call_message(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $conversation = $this->privateConversationBetween($a, $b);

        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id, 'conversation_id' => $conversation->id])
            ->json('data.id');

        $this->travel(31)->seconds();
        (new MarkMissedCallsJob())->handle(app(CallRepository::class), app(CallService::class), app(\Modules\Core\Services\PushNotificationService::class));

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'message_type' => 'system',
            'body' => 'Missed voice call',
        ]);
    }

    public function test_a_call_with_no_conversation_id_creates_no_message(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();

        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/end")->assertStatus(200);

        $this->assertDatabaseCount('messages', 0);
    }

    /**
     * Real security gap this activated: conversation_id previously
     * flowed straight from the request into the Call row with no check
     * that the caller actually belonged to that conversation - since
     * nothing consumed it before, this was dormant. Now that ending a
     * call logs a message into it, a caller supplying an arbitrary
     * conversation_id must not be able to get a message injected into a
     * conversation they're not a member of.
     */
    public function test_a_conversation_id_the_caller_does_not_belong_to_is_silently_ignored(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $stranger = $this->activeUser();
        $strangersConversation = $this->privateConversationBetween($stranger, $this->activeUser());

        $start = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id, 'conversation_id' => $strangersConversation->id]);
        $start->assertStatus(201);
        $callId = $start->json('data.id');

        $this->assertNull(Call::find($callId)->conversation_id);

        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/end")->assertStatus(200);

        $this->assertDatabaseCount('messages', 0);
    }

    /**
     * A 1:1 call is now just the N=2 case: two call_participants rows,
     * the caller in from the start and the invitee only from the moment
     * they answer (which is what joined_at being null means until then).
     */
    public function test_starting_a_call_creates_the_participant_rows_and_accepting_joins_the_invitee(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();

        $start = $this->actingAs($a, 'sanctum')->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id]);
        $callId = $start->json('data.id');

        $this->assertDatabaseHas('call_participants', ['call_id' => $callId, 'user_id' => $a->id, 'role' => 'caller']);
        $this->assertDatabaseHas('call_participants', ['call_id' => $callId, 'user_id' => $b->id, 'role' => 'participant', 'joined_at' => null]);

        // Only the caller counts as "in it" while it's still ringing.
        $start->assertJsonPath('data.participant_count', 1);

        $accept = $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept")->assertStatus(200);
        $accept->assertJsonPath('data.participant_count', 2);
        $this->assertNotNull(Call::find($callId)->participantFor($b->id)->joined_at);
    }

    /**
     * "Add member" used to end the call and escalate everyone into a
     * brand new Meeting. It must now keep the SAME call alive and simply
     * add a participant - no Meeting, no navigation, nothing ended.
     */
    public function test_adding_a_member_keeps_the_same_call_alive_and_adds_a_participant(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $c = $this->activeUser();

        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept")->assertStatus(200);

        $response = $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/add-member", ['user_id' => $c->id]);

        $response->assertStatus(201)
            ->assertJsonPath('data.call_status', 'accepted')
            ->assertJsonPath('data.participant_count', 3);

        $call = Call::find($callId);
        $this->assertSame('accepted', $call->call_status);
        $this->assertNull($call->ended_at);

        // No Meeting is created any more - the whole escalation path is
        // gone along with calls.escalated_to_meeting_id.
        $this->assertDatabaseCount('meetings', 0);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('calls', 'escalated_to_meeting_id'));

        $this->assertDatabaseHas('call_participants', ['call_id' => $callId, 'user_id' => $c->id, 'role' => 'participant']);
        $this->assertNotNull($call->participantFor($c->id)->joined_at, 'someone added mid-call joins directly rather than being rung');

        // The added member's own client discovers the call through the
        // same incoming poll a ringing call uses - status 'accepted'
        // being how it knows to join straight in instead of ringing.
        $this->actingAs($c, 'sanctum')->getJson('/api/v1/calls/incoming')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $callId)
            ->assertJsonPath('data.call_status', 'accepted')
            ->assertJsonPath('data.me.role', 'participant');
    }

    public function test_only_an_active_participant_can_add_a_member(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $stranger = $this->activeUser();
        $c = $this->activeUser();

        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/add-member", ['user_id' => $c->id])
            ->assertStatus(403);

        // Not while it's still ringing either - there's nothing to add
        // anyone to until the call has actually connected.
        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/add-member", ['user_id' => $c->id])
            ->assertStatus(409);
    }

    public function test_adding_a_member_who_is_already_on_another_call_is_rejected_as_busy(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $c = $this->activeUser();
        $d = $this->activeUser();

        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");

        // C is busy on their own call with D.
        $this->actingAs($c, 'sanctum')->postJson('/api/v1/calls/voice/start', ['receiver_id' => $d->id])->assertStatus(201);

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/add-member", ['user_id' => $c->id])
            ->assertStatus(409);

        // Adding the same person twice is idempotent, not a duplicate
        // tile for the same human.
        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/add-member", ['user_id' => $b->id])
            ->assertStatus(201)
            ->assertJsonPath('data.participant_count', 2);
        $this->assertSame(2, \Modules\Calls\Models\CallParticipant::where('call_id', $callId)->count());
    }

    /**
     * The real point of the rebuild: with three people in one call every
     * PAIR negotiates its own peer connection, so a signal must reach
     * exactly the peer it was addressed to and nobody else - mirroring
     * the Meetings module's "mesh signaling is scoped per participant
     * pair" guarantee.
     */
    public function test_in_a_three_way_call_every_pair_can_signal_each_other_privately(): void
    {
        [$a, $b, $c, $callId] = $this->threeWayCall();

        // A offers to B and, separately, to C.
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/offer", ['receiver_id' => $b->id, 'sdp' => 'sdp-a-to-b'])->assertStatus(201);
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/offer", ['receiver_id' => $c->id, 'sdp' => 'sdp-a-to-c'])->assertStatus(201);
        // And B and C negotiate their own pair, which A must never see.
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/offer", ['receiver_id' => $c->id, 'sdp' => 'sdp-b-to-c'])->assertStatus(201);
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/ice", ['receiver_id' => $c->id, 'candidate' => 'ice-b-to-c'])->assertStatus(201);

        $bSignals = collect($this->actingAs($b, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals")->json('data.signals'));
        $this->assertSame(['sdp-a-to-b'], $bSignals->pluck('payload')->all());
        $this->assertSame($a->id, $bSignals->first()['sender_id'], 'the poll must say which peer a signal came from');

        $cSignals = collect($this->actingAs($c, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals")->json('data.signals'));
        $this->assertEqualsCanonicalizing(['sdp-a-to-c', 'sdp-b-to-c'], $cSignals->pluck('payload')->all());

        $cIce = collect($this->actingAs($c, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals")->json('data.ice_candidates'));
        $this->assertSame(['ice-b-to-c'], $cIce->pluck('candidate')->all());
        $this->assertSame($b->id, $cIce->first()['sender_id']);

        // A is a participant but not a party to the B<->C pair.
        $aSignals = collect($this->actingAs($a, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals")->json('data.signals'));
        $this->assertCount(0, $aSignals, 'A must not receive signaling belonging to the B/C pair');
    }

    public function test_you_cannot_relay_signaling_to_somebody_who_is_not_in_the_call(): void
    {
        [$a, , , $callId] = $this->threeWayCall();
        $outsider = $this->activeUser();

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/offer", ['receiver_id' => $outsider->id, 'sdp' => 'sdp'])
            ->assertStatus(403);

        // Nor to yourself.
        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/offer", ['receiver_id' => $a->id, 'sdp' => 'sdp'])
            ->assertStatus(403);
    }

    /**
     * Room-wide events (reactions, screen-share start/stop) are not
     * pair-scoped: with three people, one person sharing their screen is
     * news for BOTH of the others, so it fans out one row per recipient.
     */
    public function test_reactions_and_screen_share_fan_out_to_every_other_participant(): void
    {
        [$a, $b, $c, $callId] = $this->threeWayCall();

        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/reaction", ['emoji' => '👍'])->assertStatus(201);
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/screen-share", ['state' => 'started'])->assertStatus(201);

        foreach ([$b, $c] as $recipient) {
            $signals = collect($this->actingAs($recipient, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals")->json('data.signals'));
            $this->assertSame('👍', $signals->firstWhere('signal_type', 'reaction')['payload']);
            $this->assertSame('started', $signals->firstWhere('signal_type', 'screen-share')['payload']);
            $this->assertSame($a->id, $signals->firstWhere('signal_type', 'reaction')['sender_id']);
        }

        // The sender never gets their own room event echoed back.
        $ownPoll = collect($this->actingAs($a, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals")->json('data.signals'));
        $this->assertCount(0, $ownPoll);
    }

    /**
     * Per-person media state: with three people "the caller is muted" is
     * meaningless - only "user 7 is muted" is, which is why the old
     * role-keyed media_state JSON became a real column per participant.
     */
    public function test_media_state_is_tracked_per_participant_in_a_group_call(): void
    {
        [$a, $b, $c, $callId] = $this->threeWayCall();

        $this->actingAs($b, 'sanctum')->patchJson("/api/v1/calls/{$callId}/media", ['audio_enabled' => false])->assertStatus(200);
        $this->actingAs($c, 'sanctum')->patchJson("/api/v1/calls/{$callId}/media", ['video_enabled' => false])->assertStatus(200);

        $participants = collect($this->actingAs($a, 'sanctum')->getJson("/api/v1/calls/{$callId}")->json('data.participants'))
            ->keyBy('user_id');

        $this->assertTrue($participants[$a->id]['audio_enabled']);
        $this->assertFalse($participants[$b->id]['audio_enabled']);
        $this->assertTrue($participants[$b->id]['video_enabled']);
        $this->assertFalse($participants[$c->id]['video_enabled']);
        $this->assertTrue($participants[$c->id]['audio_enabled']);

        // "The other party's" state is meaningless with two other people
        // in the call, so it reports null instead of guessing.
        $this->actingAs($a, 'sanctum')->getJson("/api/v1/calls/{$callId}")
            ->assertJsonPath('data.other_party_media_state', null);
    }

    /**
     * One person hanging up out of three must leave the other two
     * talking - it is a "leave", not an "end for everyone".
     */
    public function test_one_participant_leaving_a_three_way_call_does_not_end_it_for_the_others(): void
    {
        [$a, $b, $c, $callId] = $this->threeWayCall();

        $this->actingAs($c, 'sanctum')->postJson("/api/v1/calls/{$callId}/end")
            ->assertStatus(200)
            ->assertJsonPath('data.call_status', 'accepted')
            ->assertJsonPath('data.participant_count', 2);

        $call = Call::find($callId);
        $this->assertSame('accepted', $call->call_status);
        $this->assertNotNull($call->participantFor($c->id)->left_at);

        // Still live for A and B. C can still READ the call it just left
        // (a status poll already in flight at the moment of hanging up
        // must not 403 - that's how a client learns the outcome) but may
        // no longer act on it.
        $this->actingAs($a, 'sanctum')->getJson("/api/v1/calls/{$callId}")->assertStatus(200);
        $this->actingAs($c, 'sanctum')->getJson("/api/v1/calls/{$callId}")->assertStatus(200);
        $this->actingAs($c, 'sanctum')->postJson("/api/v1/calls/{$callId}/end")->assertStatus(403);
        $this->actingAs($c, 'sanctum')->postJson("/api/v1/calls/{$callId}/offer", ['receiver_id' => $a->id, 'sdp' => 'x'])->assertStatus(403);

        // Having left, C is free to be called again - the busy check
        // reads live membership, not "was ever in this call".
        $this->actingAs($c, 'sanctum')->postJson('/api/v1/calls/voice/start', ['receiver_id' => $this->activeUser()->id])->assertStatus(201);

        // Now B leaves too: one person left means there is nobody to
        // talk to, so the call itself finally ends and gets its history.
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/end")
            ->assertStatus(200)
            ->assertJsonPath('data.call_status', 'ended');
        $this->assertDatabaseHas('call_history', ['call_id' => $callId, 'end_reason' => 'completed', 'participant_count' => 3]);
    }

    public function test_a_group_call_shows_up_in_the_history_of_everyone_who_was_in_it(): void
    {
        [$a, $b, $c, $callId] = $this->threeWayCall();

        $this->travel(30)->seconds();
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/end");
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/end");
        $this->assertSame('ended', Call::find($callId)->call_status);

        // Including C, who was added mid-call and is therefore neither
        // the caller nor the receiver - the exact case the old
        // caller_id/receiver_id history query could never surface.
        foreach ([$a, $b, $c] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/calls/history')
                ->assertStatus(200)
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $callId);
        }

        // And filtering "calls with this contact" is a membership
        // question too, not a caller/receiver pair-match.
        $this->actingAs($a, 'sanctum')->getJson('/api/v1/calls/history?with_user_id='.$c->id)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_a_group_call_is_logged_in_the_conversation_timeline_as_a_group_call(): void
    {
        $this->freezeSecond(); // the call length is checked to the second: a real second passing during the requests made it 00:46
        $a = $this->activeUser();
        $b = $this->activeUser();
        $c = $this->activeUser();
        $conversation = $this->privateConversationBetween($a, $b);

        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id, 'conversation_id' => $conversation->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/add-member", ['user_id' => $c->id])->assertStatus(201);

        $this->travel(45)->seconds();
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/end");
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/end");

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'message_type' => 'system',
            'body' => 'Group voice call · 00:45',
        ]);
    }

    /**
     * A, B and C all in one accepted call: the shape every group-call
     * test above builds on. Returns [$a, $b, $c, $callId].
     */
    private function threeWayCall(): array
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $c = $this->activeUser();

        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept")->assertStatus(200);
        $this->actingAs($a, 'sanctum')->postJson("/api/v1/calls/{$callId}/add-member", ['user_id' => $c->id])->assertStatus(201);

        return [$a, $b, $c, $callId];
    }

    public function test_an_in_call_event_reaches_the_other_party_and_unknown_kinds_are_refused(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $callId = $this->actingAs($a, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])
            ->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept");

        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/event", ['kind' => 'poll', 'data' => ['id' => 'p1', 'q' => 'Lunch?', 'options' => ['Yes', 'No']]])
            ->assertStatus(201);
        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/event", ['kind' => 'shell', 'data' => []])
            ->assertStatus(422);
        $this->actingAs($a, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/event", ['kind' => 'caption', 'data' => ['text' => str_repeat('x', 5000)]])
            ->assertStatus(422);

        $signals = collect($this->actingAs($b, 'sanctum')->getJson("/api/v1/calls/{$callId}/signals")->json('data.signals'));
        $event = $signals->firstWhere('signal_type', 'event');
        $this->assertNotNull($event);
        $this->assertSame(['kind' => 'poll', 'data' => ['id' => 'p1', 'q' => 'Lunch?', 'options' => ['Yes', 'No']]], json_decode($event['payload'], true));
        $this->assertCount(1, $signals->where('signal_type', 'event'));

        $stranger = $this->activeUser();
        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/calls/{$callId}/event", ['kind' => 'caption', 'data' => ['text' => 'hi']])
            ->assertStatus(403);
    }

    public function test_a_person_on_a_call_gets_a_waiting_call_and_answering_it_ends_the_first(): void
    {
        $a = $this->activeUser();
        $b = $this->activeUser();
        $c = $this->activeUser();
        $d = $this->activeUser();
        $first = $this->actingAs($a, 'sanctum')->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])->json('data.id');
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$first}/accept")->assertStatus(200);

        // C calls B while B talks to A: it rings (call waiting) and shows up in B's incoming poll.
        $second = $this->actingAs($c, 'sanctum')->postJson('/api/v1/calls/video/start', ['receiver_id' => $b->id])
            ->assertStatus(201)->json('data.id');
        $this->assertSame($second, $this->actingAs($b, 'sanctum')->getJson('/api/v1/calls/incoming')->json('data.id'));

        // While that rings, a third caller gets busy.
        $this->actingAs($d, 'sanctum')->postJson('/api/v1/calls/voice/start', ['receiver_id' => $b->id])->assertStatus(409);

        // B answers: the first call ends (only A was left in it) and the second is live.
        $this->actingAs($b, 'sanctum')->postJson("/api/v1/calls/{$second}/accept")->assertStatus(200);
        $this->assertSame('ended', $this->actingAs($a, 'sanctum')->getJson("/api/v1/calls/{$first}")->json('data.call_status'));
        $this->assertSame('accepted', $this->actingAs($c, 'sanctum')->getJson("/api/v1/calls/{$second}")->json('data.call_status'));
    }
}
