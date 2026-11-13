<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Admin\Database\Seeders\AdminDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * WithoutModelEvents is intentionally not used: several models rely on
     * their `creating` event to generate UUIDs (see e.g. Role::booted()).
     */
    public function run(): void
    {
        $this->call(AdminDatabaseSeeder::class);
    }
}
