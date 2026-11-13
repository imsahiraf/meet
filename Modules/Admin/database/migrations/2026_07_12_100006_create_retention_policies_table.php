<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DDS-7 TABLE 18 "retention_policies" - one policy row per data
 * category (FR-065's File Expiration options: Never/24h/7d/30d/90d/
 * Custom apply directly to the `media` category; the same shape
 * covers Chat/Log/Archive/One-View retention per the docs' policy
 * list). No literal column schema is given - this is the judgment
 * call: retention_value + retention_unit (nullable = "Never").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('category', 100)->unique();
            $table->unsignedInteger('retention_value')->nullable();
            $table->enum('retention_unit', ['hours', 'days'])->default('days');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
    }
};
