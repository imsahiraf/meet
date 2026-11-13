<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-051 wallpaper - the chat_wallpapers table/relations already
 * existed but nothing ever populated a catalog or exposed a picker UI.
 * A data migration (not a manually-triggered seeder) so this actually
 * rolls out on the next real deploy via update.php's baseline
 * `migrate --force`, the same way every other schema/data change here
 * reaches production.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        ['name' => 'Default', 'value' => '#ffffff'],
        ['name' => 'Sand', 'value' => '#f5f0e6'],
        ['name' => 'Mint', 'value' => '#e3f5ec'],
        ['name' => 'Sky', 'value' => '#e6f0fb'],
        ['name' => 'Blush', 'value' => '#fbe9ee'],
        ['name' => 'Slate', 'value' => '#eef0f3'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::DEFAULTS as $wallpaper) {
            DB::table('chat_wallpapers')->insert([
                'uuid' => (string) Str::uuid(),
                'user_id' => null,
                'name' => $wallpaper['name'],
                'type' => 'color',
                'value' => $wallpaper['value'],
                'is_system_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('chat_wallpapers')->where('is_system_default', true)->delete();
    }
};
