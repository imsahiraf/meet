<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Calls become genuinely N-party. Until now this module's data model
 * was a hard caller/receiver PAIR - `calls.caller_id` +
 * `calls.receiver_id` were the membership list, `media_state` was a
 * JSON blob keyed by the literal strings 'caller'/'receiver', and
 * "Add member" could only work by abandoning the call and escalating
 * everyone into a Meeting instead. This table replaces all of that:
 * membership is now a real row per person, so a call can hold 2, 3 or
 * more people and grow/shrink while it's live.
 *
 * DATA-SHAPE DECISION (calls.caller_id / calls.receiver_id):
 * both columns are DELIBERATELY KEPT, but they are no longer
 * authoritative for "who is in this call".
 *   - caller_id  = who started it (historical/display metadata).
 *   - receiver_id = who it was originally started TOWARD, i.e. the one
 *     person who gets rung and is therefore the only one allowed to
 *     accept/reject while the call is still 'ringing'.
 * Everything else - who may signal, whose media state is whose, who
 * sees it in their history, whether the call is still alive - is
 * queried through `call_participants`. Keeping the pair rather than
 * migrating away from it buys three concrete things with no ongoing
 * 2-party assumption: the ring/accept/reject handshake still has an
 * unambiguous single invitee (a group call in this app always STARTS
 * as 1:1 and grows via "Add member" - see CallService::addParticipant),
 * `call_history`'s own not-null caller_id/receiver_id pair keeps
 * working unchanged, and the Call-history UI keeps a sensible "call
 * with X" headline row instead of having to invent one from a
 * participant list. A 1:1 call is simply the N=2 case: two
 * call_participants rows.
 *
 * audio_enabled/video_enabled live here as real per-person booleans,
 * replacing calls.media_state's role-keyed JSON (dropped in the next
 * migration) - with 3+ people "the caller's mute state" is meaningless;
 * only "user 7 is muted" is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_participants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('role', ['caller', 'participant'])->default('participant');

            // joined_at null = invited/ringing but not yet in the call
            // (the original receiver before they accept). left_at null =
            // still in it. "Active participant" throughout this module
            // means joined_at IS NOT NULL AND left_at IS NULL.
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();

            $table->boolean('audio_enabled')->default(true);
            $table->boolean('video_enabled')->default(true);
            $table->timestamps();

            // One membership row per person per call - "Add member" for
            // someone already in it must be idempotent, not a duplicate
            // tile for the same human.
            $table->unique(['call_id', 'user_id']);
            $table->index(['call_id', 'left_at']);
            $table->index(['user_id', 'left_at']);
        });

        $this->backfillExistingCalls();
    }

    public function down(): void
    {
        Schema::dropIfExists('call_participants');
    }

    /**
     * Every pre-existing call already has exactly two parties implied
     * by caller_id/receiver_id - without this backfill they'd all
     * become zero-participant calls, breaking call history (which now
     * reads membership from this table) for every call already on the
     * system.
     */
    private function backfillExistingCalls(): void
    {
        $hasMediaState = Schema::hasColumn('calls', 'media_state');

        DB::table('calls')->orderBy('id')->chunk(200, function ($calls) use ($hasMediaState) {
            $rows = [];
            $now = now();

            foreach ($calls as $call) {
                $mediaState = [];
                if ($hasMediaState && ! empty($call->media_state)) {
                    $mediaState = json_decode($call->media_state, true) ?: [];
                }

                // The caller was in it from the moment it started; the
                // receiver only from the moment they answered (which is
                // exactly what answered_at already records).
                $rows[] = $this->row($call, (int) $call->caller_id, 'caller', $call->started_at, $mediaState['caller'] ?? [], $now);
                $rows[] = $this->row($call, (int) $call->receiver_id, 'participant', $call->answered_at, $mediaState['receiver'] ?? [], $now);
            }

            if ($rows) {
                DB::table('call_participants')->insert($rows);
            }
        });
    }

    private function row(object $call, int $userId, string $role, $joinedAt, array $state, $now): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'call_id' => $call->id,
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => $joinedAt,
            // A call that already ended is not still "in progress" for
            // anyone - left_at mirrors the call's own ended_at.
            'left_at' => $call->ended_at,
            'audio_enabled' => $state['audio_enabled'] ?? true,
            'video_enabled' => $state['video_enabled'] ?? true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
