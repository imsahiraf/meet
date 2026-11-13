<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BrandingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_name', 'company_name', 'logo_path', 'favicon_path',
        'theme_color', 'login_background_path', 'footer_text',
        'pwa_short_name', 'pwa_background_color', 'pwa_display_mode',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
