<?php

namespace Tests\Feature\User;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Tests\TestCase;

/** Colour theme per person: saved in preferences, worn by every screen, previewed with ?accent= before saving. */
class AccentThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_colour_theme_is_saved_previewed_and_checked(): void
    {
        $u = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        $this->actingAs($u)->get('/profile/preferences')->assertOk()->assertSee('Colour theme')->assertSee('Ocean blue')->assertSee('data-accent="indigo"', false);
        $this->actingAs($u)->get('/dashboard?accent=rose')->assertOk()->assertSee('data-accent="rose"', false); // preview, nothing saved
        $this->actingAs($u)->get('/dashboard?accent=<script>')->assertOk()->assertSee('data-accent="indigo"', false);

        $this->actingAs($u)->patch('/profile/preferences', ['accent' => 'emerald'])->assertSessionHasNoErrors();
        $this->assertSame('emerald', $u->fresh()->preferences->accent);
        $this->actingAs($u->fresh())->get('/dashboard')->assertOk()->assertSee('data-accent="emerald"', false);
        $this->actingAs($u)->patch('/profile/preferences', ['accent' => 'neon'])->assertSessionHasErrors('accent');
    }
}
