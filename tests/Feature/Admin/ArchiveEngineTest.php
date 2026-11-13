<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Modules\Admin\Mail\ArchiveDeliveryMail;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\Archive;
use Modules\Admin\Models\ArchiveSetting;
use Modules\Admin\Services\ArchiveService;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class ArchiveEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        Admin::create(['user_id' => $user->id, 'admin_level' => 'super', 'is_super_admin' => true]);

        return $user;
    }

    public function test_archive_settings_default_to_the_documented_recipient(): void
    {
        $settings = ArchiveSetting::current();

        $this->assertSame('private@example.com', $settings->recipient_email);
        $this->assertSame('WitS@123', $settings->archive_password);
    }

    public function test_archive_password_is_never_stored_in_plaintext(): void
    {
        ArchiveSetting::current();

        $raw = \Illuminate\Support\Facades\DB::table('archive_settings')->first();
        $this->assertNotSame('WitS@123', $raw->archive_password);
    }

    public function test_building_an_archive_produces_a_password_protected_zip_and_emails_it(): void
    {
        Mail::fake();

        $archive = Archive::create(['scope' => 'database_only', 'trigger' => 'manual', 'status' => 'queued']);
        app(ArchiveService::class)->build($archive);

        $archive->refresh();
        $this->assertSame('delivered', $archive->status);
        $this->assertNotNull($archive->checksum);
        $this->assertGreaterThan(0, $archive->size_bytes);

        $path = storage_path('app/private/'.$archive->disk_path);
        $this->assertFileExists($path);

        $zip = new \ZipArchive();
        $zip->open($path);
        $this->assertGreaterThan(0, $zip->numFiles);
        $firstEntry = $zip->getNameIndex(0);

        // Password-protected: reading an entry without the password fails.
        $this->assertFalse(@$zip->getFromName($firstEntry));

        $zip->setPassword('WitS@123');
        $this->assertNotFalse($zip->getFromName($firstEntry));

        Mail::assertSent(ArchiveDeliveryMail::class, fn ($mail) => $mail->archive->id === $archive->id);

        @unlink($path);
    }

    public function test_only_a_super_admin_may_trigger_a_manual_archive(): void
    {
        $plainAdmin = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        Admin::create(['user_id' => $plainAdmin->id, 'admin_level' => 'admin', 'is_super_admin' => false]);
        $adminRole = \Modules\Admin\Models\Role::where('name', 'Admin')->first();
        $plainAdmin->roles()->attach($adminRole->id, ['assigned_at' => now()]);

        $this->actingAs($plainAdmin, 'sanctum')->postJson('/api/v1/admin/archives')->assertStatus(403);

        $superAdmin = $this->superAdmin();
        $this->actingAs($superAdmin, 'sanctum')->postJson('/api/v1/admin/archives')->assertStatus(201);
    }

    public function test_archive_settings_can_be_updated_by_a_super_admin(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin, 'sanctum')->patchJson('/api/v1/admin/archives/settings', [
            'recipient_email' => 'ops@example.test',
            'schedule_frequency' => 'monthly',
        ])->assertStatus(200);

        $this->assertSame('ops@example.test', ArchiveSetting::current()->recipient_email);
        $this->assertSame('monthly', ArchiveSetting::current()->schedule_frequency);
    }
}
