<?php

namespace Modules\Admin\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Admin\Console\Commands\CleanupLoadTestUsersCommand;
use Modules\Admin\Console\Commands\SeedLoadTestUsersCommand;
use Modules\Authentication\Models\User;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class AdminServiceProvider extends ModuleServiceProvider
{
    /**
     * FR-198/FR-199 - one Gate per seeded permission_key
     * (AdminDatabaseSeeder). Super Admins bypass all of these via
     * Gate::before() in App\Providers\AppServiceProvider.
     */
    public function boot(): void
    {
        parent::boot();

        foreach ([
            'manage_users', 'manage_roles', 'manage_meetings', 'manage_groups',
            'manage_files', 'view_analytics', 'manage_settings', 'manage_backups',
            'view_audit_logs',
        ] as $permissionKey) {
            Gate::define($permissionKey, fn (User $user) => $user->hasPermission($permissionKey));
        }
    }
    /**
     * The name of the module.
     */
    protected string $name = 'Admin';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'admin';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        SeedLoadTestUsersCommand::class,
        CleanupLoadTestUsersCommand::class,
    ];

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
}
