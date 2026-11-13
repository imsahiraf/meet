<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\Role;
use Modules\Admin\Services\EnvFileService;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class EnvironmentManagementTest extends TestCase
{
    use RefreshDatabase;

    private ?string $envPath = null;
    private ?string $examplePath = null;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);

        // Bound to real temp files (never the actual project .env) -
        // EnvFileService's paths are constructor-overridable
        // specifically for this.
        $this->envPath = tempnam(sys_get_temp_dir(), 'meet-test-env-');
        $this->examplePath = tempnam(sys_get_temp_dir(), 'meet-test-env-example-');

        file_put_contents($this->envPath, "APP_NAME=Meet\nMAIL_PASSWORD=super-secret\nEXISTING_BLANK=\n");
        file_put_contents($this->examplePath, "APP_NAME=\nMAIL_PASSWORD=\nEXISTING_BLANK=\nNEWLY_ADDED_KEY=\n");

        $this->app->instance(EnvFileService::class, new EnvFileService($this->envPath, $this->examplePath));
    }

    protected function tearDown(): void
    {
        if ($this->envPath && is_file($this->envPath)) {
            unlink($this->envPath);
        }
        if ($this->examplePath && is_file($this->examplePath)) {
            unlink($this->examplePath);
        }

        parent::tearDown();
    }

    private function activeUser(): User
    {
        return User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
    }

    private function adminUser(bool $superAdmin = false): User
    {
        $user = $this->activeUser();
        Admin::create(['user_id' => $user->id, 'admin_level' => $superAdmin ? 'super' : 'admin', 'is_super_admin' => $superAdmin]);

        if (! $superAdmin) {
            $adminRole = Role::where('name', 'Admin')->first();
            $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);
        }

        return $user;
    }

    public function test_a_regular_admin_without_super_admin_cannot_access_the_environment_page(): void
    {
        $admin = $this->adminUser(superAdmin: false);

        $this->actingAs($admin, 'web')->get(route('admin.environment'))->assertStatus(403);
    }

    public function test_a_non_admin_cannot_access_the_environment_page(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'web')->get(route('admin.environment'))->assertStatus(403);
    }

    public function test_super_admin_sees_env_vars_and_the_newly_added_blank_key_is_flagged(): void
    {
        $superAdmin = $this->adminUser(superAdmin: true);

        $response = $this->actingAs($superAdmin, 'web')->get(route('admin.environment'));
        $response->assertOk();
        $response->assertSee('APP_NAME');
        $response->assertSee('MAIL_PASSWORD');
        // NEWLY_ADDED_KEY exists in .env.example but not in .env at all -
        // exactly the "a new version added a key" scenario.
        $response->assertSee('NEWLY_ADDED_KEY');
    }

    public function test_saving_field_edits_with_the_wrong_password_fails_and_changes_nothing(): void
    {
        $superAdmin = $this->adminUser(superAdmin: true);

        $this->actingAs($superAdmin, 'web')->post(route('admin.environment.fields'), [
            'password' => 'wrong-password',
            'env' => ['APP_NAME' => 'Changed'],
        ])->assertSessionHasErrors('password');

        $this->assertStringContainsString('APP_NAME=Meet', file_get_contents($this->envPath));
    }

    public function test_saving_field_edits_with_the_correct_password_updates_only_the_submitted_keys(): void
    {
        $superAdmin = $this->adminUser(superAdmin: true);

        $this->actingAs($superAdmin, 'web')->post(route('admin.environment.fields'), [
            'password' => 'password',
            'env' => [
                'APP_NAME' => 'Renamed App',
                'EXISTING_BLANK' => 'now filled in',
                'MAIL_PASSWORD' => 'super-secret', // unchanged
            ],
        ])->assertSessionDoesntHaveErrors();

        $service = new EnvFileService($this->envPath, $this->examplePath);
        $this->assertSame('Renamed App', $service->get('APP_NAME'));
        $this->assertSame('now filled in', $service->get('EXISTING_BLANK'));
        $this->assertSame('super-secret', $service->get('MAIL_PASSWORD'));

        // A backup was taken before the write.
        $backups = glob(storage_path('app/env-backups/env-*.bak'));
        $this->assertNotEmpty($backups, 'expected a backup file to be created before saving');
        foreach ($backups as $backup) {
            unlink($backup);
        }
    }

    /**
     * A crafted form field name must never be able to inject an
     * arbitrary new line into the file - only real ENV_KEY-shaped
     * names are ever written.
     */
    public function test_a_malformed_key_name_in_the_submitted_form_is_silently_ignored(): void
    {
        $superAdmin = $this->adminUser(superAdmin: true);

        $this->actingAs($superAdmin, 'web')->post(route('admin.environment.fields'), [
            'password' => 'password',
            'env' => [
                'APP_NAME' => 'Still Fine',
                'not a real key; DROP TABLE users;--' => 'malicious',
            ],
        ])->assertSessionDoesntHaveErrors();

        $contents = file_get_contents($this->envPath);
        $this->assertStringNotContainsString('DROP TABLE', $contents);
    }

    public function test_replacing_the_raw_file_with_the_correct_password_writes_the_new_content_verbatim(): void
    {
        $superAdmin = $this->adminUser(superAdmin: true);
        $newContents = "APP_NAME=Totally Different\nSOME_NEW_LINE=1\n";

        $this->actingAs($superAdmin, 'web')->post(route('admin.environment.raw'), [
            'password' => 'password',
            'raw' => $newContents,
        ])->assertSessionDoesntHaveErrors();

        // Laravel's global TrimStrings middleware trims trailing
        // whitespace/newlines off every string input, including this
        // textarea - a cosmetic difference, not a real content change.
        $this->assertSame(trim($newContents), trim(file_get_contents($this->envPath)));

        $backups = glob(storage_path('app/env-backups/env-*.bak'));
        $this->assertNotEmpty($backups, 'expected a backup file to be created before the raw replace');
        foreach ($backups as $backup) {
            unlink($backup);
        }
    }

    public function test_replacing_the_raw_file_with_the_wrong_password_fails_and_changes_nothing(): void
    {
        $superAdmin = $this->adminUser(superAdmin: true);
        $original = file_get_contents($this->envPath);

        $this->actingAs($superAdmin, 'web')->post(route('admin.environment.raw'), [
            'password' => 'wrong-password',
            'raw' => 'APP_NAME=Should Not Apply',
        ])->assertSessionHasErrors('password');

        $this->assertSame($original, file_get_contents($this->envPath));
    }
}
