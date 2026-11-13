<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each row is one "epoch" of a conversation's AES-256-GCM message key.
 * A new epoch is issued whenever the conversation first turns on E2E,
 * or whenever a group's membership changes - old messages stay
 * readable under their original epoch's wrapped key, but a member
 * removed from the group can never unwrap a later epoch, and a member
 * added later can never unwrap an earlier one. The raw key itself is
 * never stored here or anywhere server-side - only per-recipient
 * wrapped copies, in conversation_key_recipients.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->unsignedInteger('key_version');
            $table->timestamps();

            $table->unique(['conversation_id', 'key_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_keys');
    }
};
