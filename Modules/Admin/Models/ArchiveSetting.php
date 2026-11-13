<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArchiveSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipient_email', 'archive_password', 'password_protection_enabled', 'schedule_frequency',
    ];

    protected function casts(): array
    {
        return [
            // FR-351: never stored in plaintext, never logged.
            'archive_password' => 'encrypted',
            'password_protection_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        // The literal "WitS@123" default from the informal Basic.prompt
        // seed is only ever written through this encrypted cast - see
        // AdminDatabaseSeeder, not hardcoded anywhere else in the app.
        return static::firstOrCreate(['id' => 1], [
            'recipient_email' => 'private@example.com',
            'archive_password' => 'WitS@123',
        ]);
    }
}
