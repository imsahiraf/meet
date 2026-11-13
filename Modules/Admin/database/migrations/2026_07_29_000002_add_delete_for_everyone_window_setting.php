<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Admin\Models\Setting;

/**
 * AdminDatabaseSeeder::seedGeneralSettings() only ever runs on a
 * brand-new install - deploy/update.php (upgrading an EXISTING
 * install) only runs migrations, never re-seeds. A real migration is
 * what actually rolls this new admin-editable setting out to
 * already-deployed installations, matching the existing General
 * settings ('session_timeout_minutes' etc.) rather than leaving the
 * delete-for-everyone window controllable only via the
 * CHAT_DELETE_FOR_EVERYONE_WINDOW_MINUTES env var (which needs a
 * redeploy to change). The env var stays as the fallback default for
 * any install where this row is somehow missing - see
 * MessageService::delete().
 */
return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['setting_key' => 'delete_for_everyone_window_minutes'],
            [
                'setting_value' => (string) config('chat.delete_for_everyone_window_minutes', 60),
                'category' => 'chat',
                'data_type' => 'integer',
                'is_editable' => true,
            ],
        );
    }

    public function down(): void
    {
        Setting::where('setting_key', 'delete_for_everyone_window_minutes')->delete();
    }
};
