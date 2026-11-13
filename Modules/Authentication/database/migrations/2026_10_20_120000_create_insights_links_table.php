<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A Meet account linked to a imsahiraf Insights login with another e-mail (the person merged their own Meet account). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insights_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('insights_email', 190)->unique();
            $t->string('org', 120)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insights_links');
    }
};
