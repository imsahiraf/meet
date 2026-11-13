<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google SSO signup only gives an email, no phone number, and creates
 * an account with no password at all (rather than a random unusable
 * hash) so "has this user ever set a real password" is a real,
 * checkable NULL rather than an assumption - matching organizer_id's
 * precedent (see make_organizer_id_nullable_on_meetings_table), this
 * uses raw SQL/manual rebuild instead of ->nullable()->change() to
 * avoid a doctrine/dbal dependency just for these two columns. MySQL
 * (production) supports a direct MODIFY; SQLite (tests only) has no
 * ALTER COLUMN at all, so that branch rebuilds the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: true);

            return;
        }

        DB::statement('ALTER TABLE users MODIFY phone VARCHAR(20) NULL');
        DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->rebuildForSqlite(nullable: false);

            return;
        }

        DB::statement('ALTER TABLE users MODIFY phone VARCHAR(20) NOT NULL');
        DB::statement('ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL');
    }

    /**
     * Builds a fresh "users_new" table (a name nothing else points at
     * yet), copies data across, drops the original "users", then
     * renames "users_new" to "users". Deliberately NOT renaming the
     * original table first - SQLite auto-rewrites every OTHER table's
     * FK definitions that reference a renamed table's old name, which
     * would leave every table with a users FK pointing at a name that
     * no longer exists once the temp table is dropped. Renaming
     * users_new (nothing references it) avoids that rewrite entirely -
     * other tables' existing "REFERENCES users(id)" text simply
     * resolves once "users" exists again.
     *
     * SQLite doesn't rename a table's indexes when the table itself is
     * renamed, so a prior run of this same rebuild (the up() that ran
     * before a later down(), or vice versa) leaves every auto-named
     * "users_new_*" index behind under its old name even though the
     * table it belongs to is now called "users" - the explicit drops
     * below clear those phantom entries before they can collide with
     * the new ones this run is about to create.
     */
    private function rebuildForSqlite(bool $nullable): void
    {
        foreach (['uuid_unique', 'email_unique', 'phone_unique', 'full_name_index', 'status_index', 'last_login_at_index', 'created_at_index'] as $suffix) {
            DB::statement("DROP INDEX IF EXISTS users_new_{$suffix}");
        }

        Schema::create('users_new', function (Blueprint $table) use ($nullable) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('full_name', 150);
            $table->string('email')->unique();
            $phone = $table->string('phone', 20);
            $password = $table->string('password');
            if ($nullable) {
                $phone->nullable();
                $password->nullable();
            }
            $table->unique('phone');
            $table->string('profile_photo')->nullable();
            $table->enum('status', ['pending', 'active', 'inactive', 'suspended'])->default('pending');
            $table->boolean('one_view_super_access')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('full_name');
            $table->index('status');
            $table->index('last_login_at');
            $table->index('created_at');
        });

        DB::statement('INSERT INTO users_new SELECT * FROM users');
        Schema::drop('users');
        Schema::rename('users_new', 'users');
    }
};
