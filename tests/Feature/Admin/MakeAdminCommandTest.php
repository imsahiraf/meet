<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Admin\Models\Admin;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class MakeAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_super_admin_from_a_password_file_that_is_then_deleted(): void
    {
        $this->seed();
        $file = tempnam(sys_get_temp_dir(), 'pw');
        file_put_contents($file, "Very-Secret-123\n");
        $this->artisan('meet:make-admin', ['email' => 'Owner@Example.com', '--name' => 'Asha', '--password-file' => $file])->assertSuccessful();
        $this->assertFileDoesNotExist($file);
        $u = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Very-Secret-123', $u->password));
        $this->assertTrue((bool) Admin::where('user_id', $u->id)->value('is_super_admin'));
        $this->artisan('meet:make-admin', ['email' => 'x@example.com', '--password-file' => '/nonexistent'])->assertFailed();
    }
}
