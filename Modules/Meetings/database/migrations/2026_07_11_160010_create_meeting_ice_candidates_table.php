<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// See 2026_07_11_160009_create_meeting_signals_table.php for rationale.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_ice_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('sender_participant_id')->constrained('meeting_participants')->cascadeOnDelete();
            $table->foreignId('receiver_participant_id')->constrained('meeting_participants')->cascadeOnDelete();
            $table->longText('candidate');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['receiver_participant_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_ice_candidates');
    }
};
