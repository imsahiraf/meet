<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A group-of-groups container above regular Groups. announcement_group_id
 * points back at the one Group auto-created alongside the community
 * (admin_only_messaging = true) - nullable only because of creation
 * order (the community row must exist before its Announcements group
 * can reference it via groups.community_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('avatar')->nullable();
            $table->foreignId('announcement_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};
