<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settings-page overhaul:
 * - auto_download_media (one global boolean) split into per-type
 *   auto_download_photos/videos/documents, matching real WhatsApp
 *   settings rather than one all-or-nothing toggle.
 * - notify_* booleans back the Settings "Notifications" section,
 *   gating the already-existing red-dot badge polling/ringtone logic
 *   rather than a new notification-center.
 * - default_wallpaper_id/font_size back the new "Chat defaults"
 *   section - applied only to a conversation's chat_settings row the
 *   first time it's created, never retroactively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->boolean('auto_download_photos')->default(false)->after('auto_download_media');
            $table->boolean('auto_download_videos')->default(false)->after('auto_download_photos');
            $table->boolean('auto_download_documents')->default(false)->after('auto_download_videos');

            $table->boolean('notify_messages')->default(true)->after('notification_sound');
            $table->boolean('notify_calls')->default(true)->after('notify_messages');
            $table->boolean('notify_meetings')->default(true)->after('notify_calls');
            $table->boolean('notify_contact_requests')->default(true)->after('notify_meetings');

            $table->foreignId('default_wallpaper_id')->nullable()->after('notify_contact_requests')->constrained('chat_wallpapers')->nullOnDelete();
            $table->enum('font_size', ['small', 'medium', 'large'])->default('medium')->after('default_wallpaper_id');
        });

        // Backfill the new per-type columns from the old global one so
        // existing users' behavior doesn't silently change underfoot,
        // then drop the now-superseded column entirely rather than
        // leaving a stale duplicate around.
        DB::table('user_preferences')->where('auto_download_media', true)->update([
            'auto_download_photos' => true,
            'auto_download_videos' => true,
            'auto_download_documents' => true,
        ]);

        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn('auto_download_media');
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->boolean('auto_download_media')->default(false)->after('theme');
        });

        DB::table('user_preferences')->where('auto_download_photos', true)->update(['auto_download_media' => true]);

        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropForeign(['default_wallpaper_id']);
            $table->dropColumn([
                'auto_download_photos', 'auto_download_videos', 'auto_download_documents',
                'notify_messages', 'notify_calls', 'notify_meetings', 'notify_contact_requests',
                'default_wallpaper_id', 'font_size',
            ]);
        });
    }
};
