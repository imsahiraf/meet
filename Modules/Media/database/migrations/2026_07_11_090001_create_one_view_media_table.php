<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS's One View spec documents single-view/count-based/time-based
 * variants plus an admin-grantable "Super Access" auto-download
 * exception, but (per the earlier documentation-gap flag raised while
 * planning Phase 3) never elaborates the Super Access mechanism beyond
 * naming it. This table implements the single-view variant, which is
 * concretely specified; count/time-based remain deferred. Super Access
 * was later implemented concretely as `users.one_view_super_access`
 * (see the 2026_07_13 migration adding it) once a decision was made on
 * what it actually grants - see OneViewService/MediaController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('one_view_media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('message_id')->unique()->constrained('messages')->cascadeOnDelete();
            $table->foreignId('viewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_view_media');
    }
};
