<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-6 TABLE 11 "feature_flags" - only example flag names are given
 * in the docs (Guest Mode, One View Media, Whiteboard, Screen Share,
 * Polls, AI Features), no literal column list. This shape (a simple
 * key/enabled toggle) is the minimal one that satisfies FR-213's
 * "enable/disable per feature" requirement without inventing scope
 * (e.g. per-role or per-user flag targeting isn't documented anywhere).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('feature_key')->unique();
            $table->string('label');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
    }
};
