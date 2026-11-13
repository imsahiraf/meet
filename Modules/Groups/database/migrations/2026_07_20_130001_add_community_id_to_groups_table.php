<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group belongs to at most one community (nullable, null-on-delete
 * so deleting a community detaches its groups rather than destroying
 * them) - matches WhatsApp's real model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('community_id')->nullable()->after('owner_id')->constrained('communities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('community_id');
        });
    }
};
