<?php

namespace Tests\Feature\Authentication;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Passkey;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class PasskeyAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_registration_options_requires_authentication(): void
    {
        $this->get('/profile/passkeys/options')->assertRedirect(route('login'));
    }

    public function test_storing_a_passkey_rejects_a_malformed_credential(): void
    {
        $user = $this->activeUser();

        $this->actingAs($user, 'web')
            ->post('/profile/passkeys', ['name' => 'My device', 'credential' => ['not' => 'a real credential']])
            ->assertSessionHasErrors('credential.id');
    }

    public function test_a_user_cannot_delete_another_users_passkey(): void
    {
        $owner = $this->activeUser();
        $intruder = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $passkey = Passkey::forceCreate([
            'user_id' => $owner->id,
            'name' => 'Owner device',
            'credential_id' => 'fake-credential-id',
            'credential' => ['type' => 'public-key'],
        ]);

        $this->actingAs($intruder, 'web')
            ->delete("/profile/passkeys/{$passkey->id}", ['password' => 'CorrectPass1!'])
            ->assertForbidden();

        $this->assertNotNull($passkey->fresh());
    }

    public function test_deleting_a_passkey_requires_the_current_password(): void
    {
        $user = $this->activeUser(['password' => bcrypt('CorrectPass1!')]);

        $passkey = Passkey::forceCreate([
            'user_id' => $user->id,
            'name' => 'My device',
            'credential_id' => 'fake-credential-id',
            'credential' => ['type' => 'public-key'],
        ]);

        $this->actingAs($user, 'web')
            ->delete("/profile/passkeys/{$passkey->id}", ['password' => 'WrongPass1!'])
            ->assertSessionHasErrors('password');
        $this->assertNotNull($passkey->fresh());

        $this->actingAs($user, 'web')
            ->delete("/profile/passkeys/{$passkey->id}", ['password' => 'CorrectPass1!'])
            ->assertSessionHasNoErrors();
        $this->assertNull($passkey->fresh());
    }

    public function test_passkey_login_options_is_reachable_while_a_guest(): void
    {
        $this->get('/passkey-login/options')
            ->assertOk()
            ->assertJsonStructure(['options' => ['challenge', 'rpId']]);
    }

    public function test_passkey_login_rejects_a_malformed_credential(): void
    {
        $this->get('/passkey-login/options');

        $this->post('/passkey-login', ['credential' => ['not' => 'a real credential']])
            ->assertSessionHasErrors('credential.id');
        $this->assertGuest('web');
    }
}
