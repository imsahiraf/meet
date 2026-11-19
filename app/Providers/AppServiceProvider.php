<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewContract;
use Illuminate\Support\ServiceProvider;
use Modules\Admin\Models\BrandingSetting;
use Modules\Admin\Models\SmtpSetting;
use Modules\Authentication\Models\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Support\InsightsConfig::apply(); // settings kept by the Insights by Meet super admin
        // Super Admins (DDS-6 admins.is_super_admin) bypass all permission checks.
        Gate::before(function (User $user, string $ability) {
            return $user->admin?->is_super_admin ? true : null;
        });

        // FR-270 - the PWA theme-color meta tag reflects the admin's
        // configured branding without requiring a code change.
        View::composer('partials.pwa-head', function (ViewContract $view) {
            $view->with('pwaThemeColor', BrandingSetting::current()->theme_color);
        });

        $this->applyAdminConfiguredSmtp();
    }

    /**
     * FR-201 - without this, the Admin Panel's SMTP settings page only
     * ever affected its own "send test email" button: every other mail
     * in the app (registration OTP, meeting invites, archive delivery)
     * is sent through Laravel's default mailer, which only ever reads
     * .env/config/mail.php. An admin changing SMTP settings through the
     * panel had no effect anywhere else - exactly the kind of
     * "configure once, never touch code again" gap Basic.prompt asked
     * to avoid. SmtpSetting::applyToMailConfig() itself no-ops on a
     * blank row, so a fresh install with no panel configuration yet
     * keeps using whatever's in .env.
     */
    private function applyAdminConfiguredSmtp(): void
    {
        if (! Schema::hasTable('smtp_settings')) {
            return;
        }

        SmtpSetting::current()->applyToMailConfig();

        // SMTP unreachable (DNS of the mail host gone, server down) -> the server's sendmail delivers instead
        $sendmail = strtok((string) config('mail.mailers.sendmail.path'), ' ');
        if (config('mail.default') === 'smtp' && ! app()->runningUnitTests() && $sendmail && @is_executable($sendmail)) {
            config(['mail.default' => 'failover']);
        }
    }
}
