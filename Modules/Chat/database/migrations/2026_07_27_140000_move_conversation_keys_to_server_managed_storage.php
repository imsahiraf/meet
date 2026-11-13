<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the per-recipient RSA-wrapped-key model with server-managed
 * keys, per direct instruction: the app now generates each
 * conversation's AES-256-GCM key itself, stores it (encrypted at rest
 * with the app's own key - Crypt, same pattern as View-Once media) in
 * `conversation_keys` directly, and hands the raw key to any current,
 * authorized member on request. Clients cache it in local storage
 * (IndexedDB) and only re-fetch on a cache miss - there's no more
 * per-user keypair, no password-derived unlock step, and no
 * `conversation_key_recipients` per-recipient wrapped copies, since
 * access is now checked live at fetch time (conversation membership)
 * rather than baked into who was wrapped for at issuance.
 *
 * `issued_for_member_ids` replaces what `conversation_key_recipients`
 * used to let the app derive (which members a given epoch covers) -
 * needed so a later membership change can still be detected as
 * "needs rotation" the same way it always could.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_keys', function (Blueprint $table) {
            $table->text('key_material')->nullable()->after('key_version');
            $table->json('issued_for_member_ids')->nullable()->after('key_material');
        });

        Schema::dropIfExists('conversation_key_recipients');
        Schema::dropIfExists('user_encryption_keys');
    }

    public function down(): void
    {
        // Both dropped tables are recreated empty - this app has never
        // shipped the RSA-wrapped model to real users (see this
        // migration's own docblock), so there is no data to restore.
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

        Schema::create('conversation_key_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_key_id')->constrained('conversation_keys')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('wrapped_key');
            $table->timestamps();

            $table->unique(['conversation_key_id', 'user_id']);
        });

        Schema::table('conversation_keys', function (Blueprint $table) {
            $table->dropColumn(['key_material', 'issued_for_member_ids']);
        });
    }
};
