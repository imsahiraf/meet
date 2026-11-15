<?php

namespace Modules\User\Support;

use Modules\Admin\Models\BrandingSetting;
use Modules\User\Models\UserPreference;

/**
 * The colour theme of a page (owner 2026-11-15: several themes, own colours, also set by the admin).
 * Order: the Preferences preview (?accent=, ?color=), the person's choice (a preset or their own colour), the admin's default
 * (Admin > Settings > Branding: a preset or "brand" = the theme colour), indigo.
 * Returns [accent, hex or null]; a hex means the whole brand scale is mixed from that one colour.
 */
class Look
{
    private static function hex(?string $v): ?string
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
    }

    public static function resolve(?object $user = null): array
    {
        $q = request();
        if ($q && array_key_exists((string) $q->query('accent'), UserPreference::ACCENTS)) {
            return [(string) $q->query('accent'), null];
        }
        if ($q && $q->query('accent') === 'custom' && ($h = self::hex((string) $q->query('color')))) {
            return ['custom', $h];
        }
        $p = $user?->preferences;
        if ($p && $p->accent === 'custom' && ($h = self::hex($p->custom_color))) {
            return ['custom', $h];
        }
        if ($p && array_key_exists((string) $p->accent, UserPreference::ACCENTS)) {
            return [(string) $p->accent, null];
        }
        try {
            $b = BrandingSetting::current();
            $d = (string) ($b->default_accent ?? '');
            if ($d === 'brand' && ($h = self::hex($b->theme_color))) {
                return ['custom', $h];
            }
            if (array_key_exists($d, UserPreference::ACCENTS)) {
                return [$d, null];
            }
        } catch (\Throwable) {
        }

        return ['indigo', null];
    }

    /** CSS of the brand scale for one colour (Tailwind's --color-brand-50 ... 900). */
    public static function css(string $hex): string
    {
        $mix = ['50' => [8, 'white'], '100' => [16, 'white'], '200' => [30, 'white'], '300' => [48, 'white'], '400' => [72, 'white'], '500' => [100, null],
            '600' => [88, 'black'], '700' => [74, 'black'], '800' => [60, 'black'], '900' => [48, 'black']];
        $out = [];
        foreach ($mix as $k => [$pct, $with]) {
            $out[] = '--color-brand-' . $k . ': ' . ($with ? "color-mix(in srgb, $hex $pct%, $with)" : $hex) . ';';
        }

        return ':root{' . implode(' ', $out) . '}';
    }
}
