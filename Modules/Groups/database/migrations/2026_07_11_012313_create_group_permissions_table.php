<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_permissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('role_id')->constrained('group_roles')->cascadeOnDelete();
            $table->string('permission_key', 60);
            $table->boolean('is_allowed')->default(false);
            $table->timestamps();

            $table->unique(['role_id', 'permission_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_permissions');
    }
};
