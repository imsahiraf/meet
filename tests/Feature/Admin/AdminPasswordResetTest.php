<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Admin\Mail\TemporaryPasswordMail;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\Role;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\DatabaseSeeder']);
    }

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['status' => 'active', 'email_verified_at' => now()], $overrides));
    }

    private function adminUser(): User
    {
        $user = $this->activeUser();
        Admin::create(['user_id' => $user->id, 'admin_level' => 'admin', 'is_super_admin' => false]);
        $adminRole = Role::where('name', 'Admin')->first();
        $user->roles()->attach($adminRole->id, ['assigned_at' => now()]);

        return $user;
    }

    public function test_non_admin_cannot_reset_a_password(): void
    {
        $target = $this->activeUser();

        $this->actingAs($this->activeUser(), 'sanctum')
            ->postJson("/api/v1/admin/users/{$target->id}/reset-password", [
                'new_password' => 'NewPass1!', 'new_password_confirmation' => 'NewPass1!',
            ])->assertStatus(403);
    }

    public function test_admin_can_permanently_reset_a_password_with_no_email_and_no_forced_change(): void
    {
        Mail::fake();
        $admin = $this->adminUser();
        $target = $this->activeUser();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$target->id}/reset-password", [
                'new_password' => 'NewPass1!', 'new_password_confirmation' => 'NewPass1!',
            ])->assertStatus(200);

        $target->refresh();
        $this->assertTrue(Hash::check('NewPass1!', $target->password));
        $this->assertFalse($target->must_change_password);
        Mail::assertNothingSent();
    }

    public function test_admin_temporary_reset_emails_the_password_and_forces_a_change(): void
    {
        Mail::fake();
        $admin = $this->adminUser();
        $target = $this->activeUser();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$target->id}/reset-password", [
                'new_password' => 'TempPass1!', 'new_password_confirmation' => 'TempPass1!', 'is_temporary' => true,
            ])->assertStatus(200);

        $target->refresh();
        $this->assertTrue($target->must_change_password);
        Mail::assertSent(TemporaryPasswordMail::class, fn ($mail) => $mail->hasTo($target->email) && $mail->temporaryPassword === 'TempPass1!');
    }

    public function test_a_temporary_reset_forces_the_user_to_a_password_change_page_before_anywhere_else(): void
    {
        $admin = $this->adminUser();
        $target = $this->activeUser();

        $token = $admin->createToken('test')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/admin/users/{$target->id}/reset-password", [
                'new_password' => 'TempPass1!', 'new_password_confirmation' => 'TempPass1!', 'is_temporary' => true,
            ]);

        // The 'auth:sanctum' middleware on that request just authenticated
        // as $admin and, per Laravel's own Authenticate middleware, called
        // Auth::shouldUse('sanctum') as a side effect - flipping the
        // *default* guard for the rest of this test process (the test
        // client reuses one app instance across simulated requests; two
        // real HTTP requests never share this). Reset it before
        // simulating the next real "browser" request below, or
        // request()->user() keeps resolving $admin via the sanctum guard
        // instead of $target's real web session.
        Auth::shouldUse('web');
        $this->flushHeaders();

        $this->post('/login', ['login' => $target->email, 'password' => 'TempPass1!'])->assertRedirect();

        // The login redirect itself lands wherever it normally would
        // (chat.index) - EnsurePasswordIsNotTemporary catches the very
        // next page load, not the login response, so the user is bounced
        // to the change-password page before ever seeing chat content.
        $this->get('/chat')->assertRedirect(route('force-password-change.show'));

        $this->post('/force-password-change', [
            'new_password' => 'BrandNewPass1!', 'new_password_confirmation' => 'BrandNewPass1!',
        ])->assertRedirect(route('chat.index'));

        $this->assertFalse($target->refresh()->must_change_password);
        $this->assertTrue(Hash::check('BrandNewPass1!', $target->password));

        $this->get('/chat')->assertOk();
    }
}
