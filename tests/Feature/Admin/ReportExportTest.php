<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\FeatureFlag;
use Modules\Admin\Models\Role;
use Modules\Authentication\Models\User;
use Tests\TestCase;

/** Report export (FR-287): CSV, Excel and PDF of the analytics, admins only. */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);
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

    public function test_admins_download_the_report_as_csv_excel_and_pdf(): void
    {
        $admin = $this->adminUser();
        $csv = $this->actingAs($admin, 'sanctum')->get('/api/v1/admin/reports/export?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->getContent();
        $this->assertStringContainsString('Section,Number,Value', $csv);
        $this->assertStringContainsString('Meetings,"Meetings made",0', $csv);

        $xlsx = $this->actingAs($admin, 'sanctum')->get('/api/v1/admin/reports/export?format=xlsx')->assertOk()->getContent();
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $xlsx);
        $z = new \ZipArchive();
        $this->assertTrue($z->open($tmp) === true);
        $this->assertStringContainsString('Meetings made', (string) $z->getFromName('xl/worksheets/sheet1.xml'));
        $z->close();
        unlink($tmp);

        $pdf = $this->actingAs($admin, 'sanctum')->get('/api/v1/admin/reports/export?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('(Meetings made) Tj', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/reports/export?format=doc')->assertStatus(422);
        $this->actingAs($this->activeUser(), 'sanctum')->get('/api/v1/admin/reports/export?format=csv')->assertForbidden();
    }
}
