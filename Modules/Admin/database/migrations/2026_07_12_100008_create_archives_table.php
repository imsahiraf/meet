<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS-21 FR-346/FR-360 - one row per archive run. `status` follows
 * the documented lifecycle (Scheduled/Queued/Processing/Compressing/
 * Verifying/Completed/Emailing/Delivered/Failed/Archived/Deleted),
 * collapsed to the subset that's actually observable as distinct
 * states in a synchronous-per-job implementation (the doc's finer
 * states like Compressing/Verifying/Emailing describe transient
 * sub-steps of "processing", not something a client ever polls for
 * mid-flight the way meeting/call statuses are).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archives', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->enum('scope', ['entire_system', 'database_only', 'files_only'])->default('entire_system');
            $table->enum('trigger', ['scheduled', 'manual'])->default('manual');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['queued', 'processing', 'completed', 'delivered', 'failed'])->default('queued');
            $table->string('disk_path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archives');
    }
};
