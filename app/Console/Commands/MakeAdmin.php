<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\Role;
use Modules\Authentication\Events\UserRegistered;
use Modules\Authentication\Listeners\CreateDefaultUserRecords;
use Modules\Authentication\Models\User;

/**
 * The first Super Admin from the command line (used by Meet Panel's one-click install; the same steps as public/install.php).
 * The password is read from a file that is deleted at once (never on the command line, where other users could see it).
 *   php artisan meet:make-admin owner@example.com --name="Asha" --password-file=storage/app/.admin-pass
 */
class MakeAdmin extends Command
{
    protected $signature = 'meet:make-admin {email} {--name=Administrator} {--password-file=}';

    protected $description = 'Create or reset the Super Admin of this Meet server';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Not an e-mail address.');

            return self::FAILURE;
        }
        $file = (string) $this->option('password-file');
        $password = $file !== '' && is_file($file) ? trim((string) file_get_contents($file)) : '';
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
        if (strlen($password) < 10) {
            $this->error('The password file is missing or the password is shorter than 10 characters.');

            return self::FAILURE;
        }
        $user = User::where('email', $email)->first() ?? new User();
        $isNew = !$user->exists;
        $user->forceFill(['full_name' => mb_substr((string) $this->option('name'), 0, 150), 'email' => $email, 'password' => Hash::make($password),
            'status' => 'active', 'email_verified_at' => now()])->save();
        if ($isNew) {
            (new CreateDefaultUserRecords())->handle(new UserRegistered($user, '000000')); // profile, privacy, settings
        }
        Admin::updateOrCreate(['user_id' => $user->id], ['admin_level' => 'super', 'is_super_admin' => true]);
        $role = Role::where('name', 'Super Admin')->first();
        if ($role && !$user->roles()->where('roles.id', $role->id)->exists()) {
            $user->roles()->attach($role->id, ['assigned_at' => now()]);
        }
        $this->info('Super Admin ready: ' . $email);

        return self::SUCCESS;
    }
}
