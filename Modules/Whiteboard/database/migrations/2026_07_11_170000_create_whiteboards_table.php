<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SRS/10.md FR-141/FR-160. meeting_id references Meetings' table directly
// (cross-module FK, same pattern as Media's messages/media_id link).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whiteboards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->string('title')->default('Whiteboard');
            $table->foreignId('created_by')->constrained('users');
            $table->enum('permission_mode', ['host_only', 'host_and_co_host', 'all_participants', 'view_only'])->default('all_participants');
            $table->enum('status', ['active', 'closed', 'archived'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whiteboards');
    }
};
