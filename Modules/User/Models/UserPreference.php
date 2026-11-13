<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\ChatWallpaper;

class UserPreference extends Model
{
    use HasFactory;

    /** Colour themes of the app (resources/css/app.css :root[data-accent]): name, main colour, light tint for the cards. */
    public const ACCENTS = [
        'indigo' => ['Indigo (default)', '#4f46e5', '#eef2ff'], 'ocean' => ['Ocean blue', '#0284c7', '#f0f9ff'], 'emerald' => ['Emerald', '#059669', '#ecfdf5'],
        'teal' => ['Teal', '#0d9488', '#f0fdfa'], 'rose' => ['Rose', '#e11d48', '#fff1f2'], 'sunset' => ['Sunset orange', '#ea580c', '#fff7ed'],
        'plum' => ['Plum', '#9333ea', '#faf5ff'], 'graphite' => ['Graphite', '#334155', '#f8fafc'],
    ];

    protected $fillable = [
        'uuid',
        'user_id',
        'language',
        'timezone',
        'theme',
        'accent',
        'notification_sound',
        'auto_download_photos',
        'auto_download_videos',
        'auto_download_documents',
        'notify_messages',
        'notify_calls',
        'notify_meetings',
        'notify_contact_requests',
        'default_wallpaper_id',
        'font_size',
    ];

    protected function casts(): array
    {
        return [
            'notification_sound' => 'boolean',
            'auto_download_photos' => 'boolean',
            'auto_download_videos' => 'boolean',
            'auto_download_documents' => 'boolean',
            'notify_messages' => 'boolean',
            'notify_calls' => 'boolean',
            'notify_meetings' => 'boolean',
            'notify_contact_requests' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $preference) {
            $preference->uuid ??= (string) Str::uuid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultWallpaper(): BelongsTo
    {
        return $this->belongsTo(ChatWallpaper::class, 'default_wallpaper_id');
    }
}
