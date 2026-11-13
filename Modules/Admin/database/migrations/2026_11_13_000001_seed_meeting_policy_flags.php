<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Meeting policies: the switches the server enforces (EnsureFeatureEnabled), all on by default; existing choices are kept. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('feature_flags')) {
            return;
        }
        $flags = ['guest_mode' => 'Guests without an account can join meetings', 'recording' => 'Meeting recording', 'screen_share' => 'Screen sharing',
            'whiteboard' => 'Whiteboard', 'breakouts' => 'Breakout rooms', 'captions' => 'Live captions', 'ai_features' => 'AI meeting summary'];
        foreach ($flags as $key => $label) {
            DB::table('feature_flags')->insertOrIgnore(['feature_key' => $key, 'label' => $label, 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
    }
};
