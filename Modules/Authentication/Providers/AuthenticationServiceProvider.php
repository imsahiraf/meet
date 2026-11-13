<?php

namespace Modules\Authentication\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Passkeys\Passkeys;
use Modules\Authentication\Models\User;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AuthenticationServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Authentication';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'authentication';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     *
     * @param $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }

    public function register(): void
    {
        parent::register();

        Passkeys::useUserModel(User::class);

        // Must happen in register(), not boot() - every provider's
        // register() runs before any provider's boot(), and the
        // package's own PasskeysServiceProvider::boot() is what
        // actually calls loadRoutesFrom() when this flag is still true.
        // Its routes are built around Laravel's password-confirmation
        // middleware/flow, which this app doesn't use anywhere else
        // (every other step-up action here re-checks the current
        // password inline in the same form instead - see 2FA
        // disable/regenerate, changePassword, deactivateAccount).
        // Hand-written routes/controllers (registered in this module's
        // and User's routes/web.php) reuse the package's own actions
        // directly but wire login through
        // AuthenticationService::establishWebSession() so it stays
        // tracked in user_sessions/UserLoggedIn like every other login
        // path, and gate registration/removal the same
        // inline-password-recheck way as everything else in Settings.
        Passkeys::ignoreRoutes();
    }
}
