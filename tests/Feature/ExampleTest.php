<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Task 3.1 - "/" redirects based on authentication status instead of
     * showing Laravel's default welcome page.
     */
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_authenticated_users_are_redirected_to_chat(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        $this->actingAs($user, 'web')->get('/')->assertRedirect(route('chat.index'));
    }
}
