<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Screen-share start/stop during a 1:1 call rides the same
 * polling-based signal channel offer/answer/reaction already use (see
 * SignalingController) - just a fourth signal_type value. Needed as an
 * explicit application-level signal (not just "a video track showed
 * up") because a voice call's video transceiver is pre-provisioned
 * with no track from the start (see CallService/room-script's
 * setupPeerConnection docblock) specifically so screen share never
 * needs SDP renegotiation - the receiving side has no other reliable
 * way to know whether an incoming video track means "camera turned
 * on" vs "screen share started", or when it stops.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->widenSignalType(['offer', 'answer', 'reaction', 'screen-share']);
    }

    public function down(): void
    {
        $this->widenSignalType(['offer', 'answer', 'reaction']);
    }

    /**
     * See the 2026_07_28_000001 migration's docblock for why SQLite
     * needs a full table rebuild here rather than an ALTER.
     */
    private function widenSignalType(array $allowed): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE signaling_messages DROP CONSTRAINT IF EXISTS signaling_messages_signal_type_check');
            $quoted = collect($allowed)->map(fn ($v) => "'".$v."'")->implode(', ');
            DB::statement("ALTER TABLE signaling_messages ADD CONSTRAINT signaling_messages_signal_type_check CHECK (signal_type IN ({$quoted}))");

            return;
        }

        if ($driver === 'sqlite') {
            // A distinctly-named temp table (not the same
            // "signaling_messages_rebuild" the previous migration
            // used) - SQLite doesn't rename a table's indexes when
            // the table itself is renamed, so the earlier migration's
            // rebuild left an index named
            // signaling_messages_rebuild_call_id_id_index still
            // attached to the now-renamed signaling_messages table;
            // reusing that same temp table name here collides with
            // that leftover index name when this migration tries to
            // create its own identically-named one.
            Schema::create('signaling_messages_rebuild_v2', function (Blueprint $table) use ($allowed) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users');
                $table->foreignId('receiver_id')->constrained('users');
                $table->enum('signal_type', $allowed);
                $table->longText('payload');
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('expires_at');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['call_id', 'id']);
            });

            DB::statement('INSERT INTO signaling_messages_rebuild_v2 SELECT * FROM signaling_messages');
            Schema::drop('signaling_messages');
            Schema::rename('signaling_messages_rebuild_v2', 'signaling_messages');

            return;
        }

        $quoted = collect($allowed)->map(fn ($v) => "'".$v."'")->implode(', ');
        DB::statement("ALTER TABLE signaling_messages MODIFY signal_type ENUM({$quoted}) NOT NULL");
    }
};
