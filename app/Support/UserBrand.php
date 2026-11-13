<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * White label in Meet: a person whose Insights organization belongs to a reseller sees the reseller's name, logo and colour
 * (sent by Insights with each sign-in, kept on insights_links). Everyone else sees Meet as it is.
 */
class UserBrand
{
    private static array $cache = [];

    /** @return array{name: string, logo: ?string, color: ?string, support_email: ?string}|null */
    public static function for(?int $userId): ?array
    {
        if (!$userId || !Schema::hasColumn('insights_links', 'brand')) {
            return null;
        }
        if (!array_key_exists($userId, self::$cache)) {
            $raw = DB::table('insights_links')->where('user_id', $userId)->whereNotNull('brand')->orderByDesc('updated_at')->value('brand');
            $b = $raw ? json_decode((string) $raw, true) : null;
            self::$cache[$userId] = is_array($b) && !empty($b['name']) ? [
                'name' => mb_substr((string) $b['name'], 0, 60),
                'logo' => isset($b['logo']) && preg_match('#^https://#i', (string) $b['logo']) ? (string) $b['logo'] : null,
                'color' => isset($b['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $b['color']) ? $b['color'] : null,
                'support_email' => isset($b['support_email']) && filter_var($b['support_email'], FILTER_VALIDATE_EMAIL) ? $b['support_email'] : null,
            ] : null;
        }

        return self::$cache[$userId];
    }

    /** Keep the brand Insights sent (null = the organization has no reseller brand any more). */
    public static function remember(string $insightsEmail, mixed $brand): void
    {
        if (Schema::hasColumn('insights_links', 'brand')) {
            DB::table('insights_links')->where('insights_email', $insightsEmail)->update(['brand' => is_array($brand) ? json_encode($brand) : null]);
        }
        self::$cache = [];
    }
}
