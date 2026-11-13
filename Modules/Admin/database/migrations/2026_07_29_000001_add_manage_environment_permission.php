<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Admin\Models\Permission;
use Modules\Admin\Models\Role;

/**
 * AdminDatabaseSeeder only ever runs on a brand-new install
 * (deploy/initialize.php's migrate --seed) - deploy/update.php
 * (upgrading an EXISTING install) only runs migrations, never
 * re-seeds. A real migration is what actually rolls a new permission
 * out to already-deployed installations, same as this one adds
 * "Manage Environment Variables" - deliberately Super Admin only
 * (never granted to the plain Admin role), matching manage_backups'
 * own precedent: editing .env can expose DB/mail/API credentials, at
 * least as sensitive as the archive-download permission that
 * precedent was set for.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::updateOrCreate(
            ['permission_key' => 'manage_environment'],
            ['permission_name' => 'Manage Environment Variables', 'module' => 'admin'],
        );

        $superAdmin = Role::where('name', 'Super Admin')->first();
        if ($superAdmin) {
            $superAdmin->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    public function down(): void
    {
        Permission::where('permission_key', 'manage_environment')->delete();
    }
};
