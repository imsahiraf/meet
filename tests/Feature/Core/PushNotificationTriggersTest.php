<?php

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Models\User;
use Modules\Calls\Jobs\MarkMissedCallsJob;
use Modules\Calls\Repositories\CallRepository;
use Modules\Calls\Services\CallService;
use Modules\Chat\Models\Conversation;
use Modules\Core\Services\PushNotificationService;
use Modules\Meetings\Jobs\SendCalendarRemindersJob;
use Modules\Meetings\Services\CalendarService;
use Tests\TestCase;

/**
 * Verifies the three trigger points (new message, incoming call,
 * contact request) call PushNotificationService::sendToUser() exactly
 * when the recipient's matching notify_* Settings toggle is on, and
 * never when it's off - mocked here since a real send needs a live
 * push endpoint (see PushSubscriptionTest for that side).
 */
class PushNotificationTriggersTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_sending_a_message_pushes_to_a_recipient_with_notifications_on_but_not_one_with_them_off(): void
    {
        $sender = $this->activeUser();
        $wantsPush = $this->activeUser();
        $mutedPush = $this->activeUser();
        $mutedPush->preferences()->create(['notify_messages' => false]);

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/conversations', ['participant_id' => $wantsPush->id]);
        $conversationId = Conversation::where('uuid', $response->json('data.uuid'))->first()->id;

        $conversation = Conversation::find($conversationId);
        $conversation->members()->create(['user_id' => $mutedPush->id, 'role' => 'member']);

        $this->mock(PushNotificationService::class, function ($mock) use ($wantsPush, $mutedPush) {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->withArgs(fn ($user) => $user->id === $wantsPush->id);
            $mock->shouldNotReceive('sendToUser')->withArgs(fn ($user) => $user->id === $mutedPush->id);
        });

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/messages', ['conversation_id' => $conversationId, 'body' => 'hey'])
            ->assertStatus(201);
    }

    /**
     * This app deliberately does NOT use a zero-knowledge E2E scheme -
     * the server generates and holds every conversation's key material
     * itself (see ConversationEncryptionService's docblock) - so a real
     * push notification should show the actual message, not a bare
     * "New message", for exactly the same reason the server is trusted
     * to issue the key in the first place.
     */
    public function test_sending_an_e2e_message_pushes_the_real_decrypted_text_not_a_generic_placeholder(): void
    {
        $sender = $this->activeUser();
        $receiver = $this->activeUser();

        $response = $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/conversations', ['participant_id' => $receiver->id]);
        $conversationId = Conversation::where('uuid', $response->json('data.uuid'))->first()->id;

        $status = $this->actingAs($sender, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversationId}/encryption")
            ->json('data');
        $keyVersion = $status['current_key_version'];

        $keyMaterial = $this->actingAs($sender, 'sanctum')
            ->getJson("/api/v1/conversations/{$conversationId}/encryption/keys/{$keyVersion}")
            ->json('data.key_material');

        $plaintext = 'a real secret message, decrypted server-side for the push preview';
        $key = base64_decode($keyMaterial);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        $this->mock(PushNotificationService::class, function ($mock) use ($receiver, $plaintext) {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->withArgs(fn ($user, $title, $body) => $user->id === $receiver->id && $body === $plaintext);
        });

        $this->actingAs($sender, 'sanctum')->postJson('/api/v1/messages', [
            'conversation_id' => $conversationId,
            'body' => base64_encode($ciphertext.$tag),
            'encryption_mode' => 'e2e',
            'iv' => base64_encode($iv),
            'key_version' => $keyVersion,
        ])->assertStatus(201);
    }

    public function test_starting_a_call_pushes_to_the_receiver_only_when_their_call_notifications_are_on(): void
    {
        $caller = $this->activeUser();
        $receiver = $this->activeUser();
        $receiver->preferences()->create(['notify_calls' => false]);

        $this->mock(PushNotificationService::class, function ($mock) {
            $mock->shouldNotReceive('sendToUser');
        });

        $this->actingAs($caller, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $receiver->id])
            ->assertStatus(201);
    }

    /**
     * `renotify: true` plus a stable `tag: 'call-'.$call->id` were
     * already set on the call push in public/service-worker.js - that
     * alone would re-trigger the OS's own native notification sound
     * each time a new push with that same tag arrives, the same way a
     * real incoming call keeps ringing rather than buzzing once. The
     * only piece genuinely missing was a second push ever being sent -
     * this piggybacks the repeat on the caller's own client already
     * polling GET /calls/{id} every ~2s for the whole ring window
     * (pollCallStatus() in app.blade.php), see
     * CallService::resendRingingPushIfDue()'s own docblock for why
     * that's the mechanism rather than a real scheduled/queued job.
     */
    public function test_the_callers_own_status_poll_resends_the_ringing_push_while_still_ringing_but_not_after_or_too_often(): void
    {
        $caller = $this->activeUser();
        $receiver = $this->activeUser();

        $this->mock(PushNotificationService::class, function ($mock) use ($receiver) {
            // start() = 1, first poll while ringing = 1 more = 2 total.
            // A second poll milliseconds later must NOT resend again
            // (Cache::add()'s 2-second window), and neither must a poll
            // once the call is no longer ringing.
            $mock->shouldReceive('sendToUser')
                ->twice()
                ->withArgs(fn ($user, $title, $body, $data) => $user->id === $receiver->id && $data['type'] === 'call');
        });

        $callId = $this->actingAs($caller, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $receiver->id])
            ->json('data.id');

        $this->actingAs($caller, 'sanctum')->getJson("/api/v1/calls/{$callId}")->assertStatus(200);
        $this->actingAs($caller, 'sanctum')->getJson("/api/v1/calls/{$callId}")->assertStatus(200);

        $this->actingAs($receiver, 'sanctum')->postJson("/api/v1/calls/{$callId}/accept")->assertStatus(200);
        $this->actingAs($caller, 'sanctum')->getJson("/api/v1/calls/{$callId}")->assertStatus(200);
    }

    /**
     * Only the caller's own client polls show() while ringing in
     * practice (see CallController::show()'s own comment) - this locks
     * in that the gate is on WHO is asking, not just call_status alone,
     * so some other participant's own unrelated poll of this same
     * endpoint could never double up the repeat push.
     */
    public function test_the_receivers_own_poll_of_a_still_ringing_call_does_not_resend_the_push(): void
    {
        $caller = $this->activeUser();
        $receiver = $this->activeUser();

        $this->mock(PushNotificationService::class, function ($mock) use ($receiver) {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->withArgs(fn ($user, $title, $body, $data) => $user->id === $receiver->id && $data['type'] === 'call');
        });

        $callId = $this->actingAs($caller, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $receiver->id])
            ->json('data.id');

        $this->actingAs($receiver, 'sanctum')->getJson("/api/v1/calls/{$callId}")->assertStatus(200);
    }

    /**
     * A missed call needs its own follow-up push (the "incoming call"
     * one is long gone from the shade by the time it's actually
     * missed) carrying enough data (call_id/caller_id/conversation_id)
     * for the service worker to offer "Call back" and "Message"
     * actions - see MarkMissedCallsJob and public/service-worker.js.
     */
    public function test_a_missed_call_pushes_to_the_receiver_with_call_back_data_only_when_their_toggle_is_on(): void
    {
        $caller = $this->activeUser();
        $otherCaller = $this->activeUser();
        $wantsPush = $this->activeUser();
        $mutedPush = $this->activeUser();
        $mutedPush->preferences()->create(['notify_calls' => false]);

        $callId = $this->actingAs($caller, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $wantsPush->id])
            ->json('data.id');
        \Modules\Calls\Models\Call::find($callId)->forceFill(['started_at' => now()->subSeconds(CallService::ringTimeoutSeconds() + 5)])->save();

        $mutedCallId = $this->actingAs($otherCaller, 'sanctum')
            ->postJson('/api/v1/calls/voice/start', ['receiver_id' => $mutedPush->id])
            ->json('data.id');
        \Modules\Calls\Models\Call::find($mutedCallId)->forceFill(['started_at' => now()->subSeconds(CallService::ringTimeoutSeconds() + 5)])->save();

        $this->mock(PushNotificationService::class, function ($mock) use ($wantsPush, $mutedPush, $callId) {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->withArgs(fn ($user, $title, $body, $data) => $user->id === $wantsPush->id
                    && $data['type'] === 'missed_call'
                    && $data['call_id'] === $callId
                    && ! empty($data['caller_id']));
            $mock->shouldNotReceive('sendToUser')->withArgs(fn ($user) => $user->id === $mutedPush->id);
        });

        (new MarkMissedCallsJob())->handle(app(CallRepository::class), app(CallService::class), app(PushNotificationService::class));
    }

    public function test_sending_a_contact_request_pushes_to_the_receiver_when_their_toggle_is_on(): void
    {
        $sender = $this->activeUser();
        $receiver = $this->activeUser();

        $this->mock(PushNotificationService::class, function ($mock) use ($receiver) {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->withArgs(fn ($user) => $user->id === $receiver->id);
        });

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/contacts/request', ['receiver_id' => $receiver->id])
            ->assertStatus(201);
    }

    public function test_a_due_meeting_reminder_pushes_to_the_invitee_only_when_their_meeting_notifications_are_on(): void
    {
        Mail::fake();
        $organizer = $this->activeUser();
        $wantsPush = $this->activeUser();
        $mutedPush = $this->activeUser();
        $mutedPush->preferences()->create(['notify_meetings' => false]);

        $this->actingAs($organizer, 'sanctum')->postJson('/api/v1/meetings', [
            'title' => 'Reminder push test',
            'scheduled_start' => now()->addMinutes(5)->toISOString(),
            'scheduled_end' => now()->addMinutes(35)->toISOString(),
            'timezone' => 'UTC',
            'participant_ids' => [$wantsPush->id, $mutedPush->id],
        ])->assertStatus(201);

        $this->mock(PushNotificationService::class, function ($mock) use ($wantsPush, $mutedPush) {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->withArgs(fn ($user) => $user->id === $wantsPush->id);
            $mock->shouldNotReceive('sendToUser')->withArgs(fn ($user) => $user->id === $mutedPush->id);
        });

        (new SendCalendarRemindersJob())->handle(app(CalendarService::class));
    }
}
