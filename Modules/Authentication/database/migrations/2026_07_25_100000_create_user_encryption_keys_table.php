<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The private key is generated client-side (WebCrypto) and never leaves
 * the browser in plaintext - what's stored here is the RSA-OAEP public
 * key (safe to be world-readable, needed by other users to wrap
 * conversation keys for this user) plus the private key encrypted with
 * an AES-GCM key derived from the user's own login password (PBKDF2,
 * client-side). The server never sees the password-derived key or the
 * plaintext private key at any point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_encryption_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->text('public_key');
            $table->text('encrypted_private_key');
            $table->string('private_key_iv');
            $table->string('salt');
            $table->unsignedInteger('kdf_iterations');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_encryption_keys');
    }
};
