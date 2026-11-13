<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation's raw AES key, RSA-OAEP-encrypted separately for
 * each recipient with their own public key - only that recipient's
 * private key (held client-side, unlockable only with their password)
 * can ever unwrap it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_key_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_key_id')->constrained('conversation_keys')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('wrapped_key');
            $table->timestamps();

            $table->unique(['conversation_key_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_key_recipients');
    }
};
