<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * In-call events (owner 2026-11-08, call features of the well-known call apps): captions, polls, votes, questions,
 * "I turned my video on", "send me less data". One more signal_type value on the same polled channel; the payload is
 * a small JSON object {kind, data}. Same widening as the screen-share migration (SQLite needs a table rebuild).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->widenSignalType(['offer', 'answer', 'reaction', 'screen-share', 'event']);
    }

    public function down(): void
    {
        $this->widenSignalType(['offer', 'answer', 'reaction', 'screen-share']);
    }

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
            // Own temp table name: SQLite keeps the index names of the earlier rebuilds.
            Schema::create('signaling_messages_rebuild_v3', function (Blueprint $table) use ($allowed) {
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

            DB::statement('INSERT INTO signaling_messages_rebuild_v3 SELECT * FROM signaling_messages');
            Schema::drop('signaling_messages');
            Schema::rename('signaling_messages_rebuild_v3', 'signaling_messages');

            return;
        }

        $quoted = collect($allowed)->map(fn ($v) => "'".$v."'")->implode(', ');
        DB::statement("ALTER TABLE signaling_messages MODIFY signal_type ENUM({$quoted}) NOT NULL");
    }
};
