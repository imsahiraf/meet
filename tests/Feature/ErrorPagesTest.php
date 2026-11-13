<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Tests\TestCase;

/**
 * Task 3.1 - custom 404/403/500 error pages must actually render (not
 * throw while rendering). Anonymous Blade components don't inherit a
 * parent view's local variables, so $errors being passed locally by
 * Laravel's exception Handler (rather than via the globally-shared
 * binding normal requests get) previously caused these pages to crash
 * with a 500 instead of showing the intended error page.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_web_route_renders_the_custom_404_page(): void
    {
        $this->get('/this-route-does-not-exist')
            ->assertStatus(404)
            ->assertSee('Page not found');
    }

    public function test_404_page_renders_for_authenticated_users_too(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        $this->actingAs($user, 'web')
            ->get('/this-route-does-not-exist')
            ->assertStatus(404)
            ->assertSee('Page not found');
    }
}
