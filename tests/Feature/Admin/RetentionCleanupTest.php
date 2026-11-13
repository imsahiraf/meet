<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Jobs\CleanupRetentionJob;
use Modules\Admin\Models\ActivityLog;
use Modules\Admin\Models\RetentionPolicy;
use Modules\Authentication\Models\User;
use Modules\Media\Models\Media;
use Tests\TestCase;

class RetentionCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);
    }

    public function test_media_past_its_retention_window_is_deleted_but_recent_media_is_kept(): void
    {
        Storage::fake('local');
        $uploader = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        Storage::disk('local')->put('media/old.jpg', 'x');
        $old = Media::create([
            'uploader_id' => $uploader->id, 'disk' => 'local', 'path' => 'media/old.jpg',
            'original_filename' => 'old.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1,
            'media_type' => 'image',
        ]);
        $old->forceFill(['created_at' => now()->subDays(60)])->save();

        Storage::disk('local')->put('media/recent.jpg', 'x');
        $recent = Media::create([
            'uploader_id' => $uploader->id, 'disk' => 'local', 'path' => 'media/recent.jpg',
            'original_filename' => 'recent.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1,
            'media_type' => 'image',
        ]);

        (new CleanupRetentionJob())->handle();

        $this->assertModelMissing($old);
        Storage::disk('local')->assertMissing('media/old.jpg');

        $this->assertModelExists($recent);
        Storage::disk('local')->assertExists('media/recent.jpg');
    }

    public function test_never_retention_exempts_a_category_from_cleanup(): void
    {
        RetentionPolicy::where('category', RetentionPolicy::AUDIT_LOGS)->update(['retention_value' => null]);

        $log = \Modules\Admin\Models\AuditLog::create(['module' => 'admin', 'action' => 'test']);
        $log->forceFill(['created_at' => now()->subYears(5)])->save();

        (new CleanupRetentionJob())->handle();

        $this->assertModelExists($log);
    }

    public function test_activity_logs_past_retention_are_removed(): void
    {
        $log = ActivityLog::create(['event' => 'login']);
        $log->forceFill(['created_at' => now()->subDays(200)])->save();

        (new CleanupRetentionJob())->handle();

        $this->assertModelMissing($log);
    }
}
