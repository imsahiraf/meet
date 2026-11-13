<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-3 notes actual files are managed by the Media module - that
 * module doesn't exist yet (it's Phase 3 per the master roadmap), so
 * media_id is stored unconstrained for now rather than FK'd to a table
 * that doesn't exist. This index table is functional but will stay
 * empty until Phase 3 wires real uploads into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->unsignedBigInteger('media_id')->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamp('created_at')->useCurrent();

            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_media');
    }
};
