<?php

namespace Modules\Admin\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmtpSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'host', 'port', 'encryption', 'username', 'password',
        'from_name', 'from_email', 'last_tested_at', 'last_test_succeeded',
    ];

    protected function casts(): array
    {
        return [
            // FR-201/FR-351-style rule: credentials never sit in
            // plaintext at rest.
            'password' => 'encrypted',
            'last_tested_at' => 'datetime',
            'last_test_succeeded' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    /**
     * Applies this row onto Laravel's mail config, but only when a
     * host is actually set - a blank row (the correct state for "just
     * use .env") must never null out an otherwise-working .env-based
     * mailer. Shared by AppServiceProvider (every real mail send) and
     * AdminSettingsController::testSmtp() (the panel's "send test
     * email" button) - they diverged once before, which let a blank
     * row silently null out mail.mailers.smtp.host only for the test
     * button (crashing with a Dsn host-must-be-string error) while
     * normal mail kept working fine off .env.
     */
    public function applyToMailConfig(): bool
    {
        if (blank($this->host)) {
            return false;
        }

        config([
            'mail.mailers.smtp.host' => $this->host,
            'mail.mailers.smtp.port' => $this->port,
            'mail.mailers.smtp.encryption' => $this->encryption === 'none' ? null : $this->encryption,
            'mail.mailers.smtp.username' => $this->username,
            'mail.mailers.smtp.password' => $this->password,
            'mail.from.address' => $this->from_email ?: config('mail.from.address'),
            'mail.from.name' => $this->from_name ?: config('mail.from.name'),
        ]);

        return true;
    }
}
