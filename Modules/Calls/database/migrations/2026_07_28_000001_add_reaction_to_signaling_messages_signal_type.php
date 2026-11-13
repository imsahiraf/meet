<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A quick emoji reaction during a call rides the same polling-based
 * signal channel offer/answer already use (see SignalingController::
 * reaction()) - just a third signal_type value, not a new mechanism.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->widenSignalType(['offer', 'answer', 'reaction']);
    }

    public function down(): void
    {
        $this->widenSignalType(['offer', 'answer']);
    }

    /**
     * MySQL/Postgres can alter the constraint in place; SQLite's
     * enum() is a CHECK constraint baked in at CREATE TABLE time with
     * no ALTER COLUMN/DROP CONSTRAINT support at all, so the only way
     * to actually widen it there (rather than silently leaving the old,
     * narrower CHECK enforced - which genuinely rejects a 'reaction'
     * row outright, confirmed by a real failing test before this fix)
     * is the standard SQLite rebuild: create a new table with the
     * wider constraint, copy every row across, then swap it in.
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
            Schema::create('signaling_messages_rebuild', function (Blueprint $table) use ($allowed) {
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

            DB::statement('INSERT INTO signaling_messages_rebuild SELECT * FROM signaling_messages');
            Schema::drop('signaling_messages');
            Schema::rename('signaling_messages_rebuild', 'signaling_messages');

            return;
        }

        $quoted = collect($allowed)->map(fn ($v) => "'".$v."'")->implode(', ');
        DB::statement("ALTER TABLE signaling_messages MODIFY signal_type ENUM({$quoted}) NOT NULL");
    }
};
