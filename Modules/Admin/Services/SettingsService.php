<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Admin\Models\Setting;

/**
 * DDS-6 "settings" - the general registry backing every "Admin
 * Configurable" value referenced across the SRS that doesn't have
 * its own dedicated table (branding/SMTP/feature flags do).
 */
class SettingsService
{
    private const CACHE_KEY = 'admin.settings.all';

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value, string $category = 'general', string $dataType = 'string'): Setting
    {
        $stored = $dataType === 'json' ? json_encode($value) : (string) $value;

        $setting = Setting::updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $stored, 'category' => $category, 'data_type' => $dataType],
        );

        Cache::forget(self::CACHE_KEY);

        return $setting;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return Setting::all()->mapWithKeys(fn (Setting $setting) => [$setting->setting_key => $setting->castValue()])->all();
        });
    }

    public function byCategory(string $category): array
    {
        return Setting::where('category', $category)->get()
            ->mapWithKeys(fn (Setting $setting) => [$setting->setting_key => $setting->castValue()])
            ->all();
    }
}
