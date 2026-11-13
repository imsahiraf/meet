<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('conversation_id')->unique()->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->string('avatar')->nullable();
            $table->string('invite_code', 50)->nullable()->unique();
            $table->unsignedInteger('member_limit')->default(512);
            $table->boolean('approval_required')->default(false);

            // FR-079 group-level permission toggles.
            $table->boolean('admin_only_messaging')->default(false);
            $table->boolean('admin_only_edit_info')->default(true);
            $table->boolean('admin_only_invite')->default(false);
            $table->boolean('admin_only_pin')->default(true);
            $table->boolean('allow_member_reactions')->default(true);
            $table->boolean('allow_member_file_sharing')->default(true);

            $table->enum('status', ['active', 'archived', 'deleted'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
