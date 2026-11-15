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
    public function test_own_colour_and_the_admin_default_theme(): void
    {
        $u = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);
        // the admin's default for everybody who has not chosen
        \Modules\Admin\Models\BrandingSetting::current()->update(['default_accent' => 'plum']);
        $this->actingAs($u)->get('/dashboard')->assertOk()->assertSee('data-accent="plum"', false);
        \Modules\Admin\Models\BrandingSetting::current()->update(['default_accent' => 'brand', 'theme_color' => '#0a7d5a']);
        $this->actingAs($u)->get('/dashboard')->assertOk()->assertSee('data-accent="custom"', false)->assertSee('--color-brand-500: #0a7d5a', false);

        // a person's own colour wins; a bad colour is refused; the preview shows an unsaved own colour
        $this->actingAs($u)->patch('/profile/preferences', ['accent' => 'custom', 'custom_color' => 'red'])->assertSessionHasErrors('custom_color');
        $this->actingAs($u)->patch('/profile/preferences', ['accent' => 'custom', 'custom_color' => '#ff3366'])->assertSessionHasNoErrors();
        $this->actingAs($u->fresh())->get('/dashboard')->assertOk()->assertSee('--color-brand-500: #ff3366', false);
        $this->actingAs($u->fresh())->get('/dashboard?accent=custom&color=%23123456')->assertOk()->assertSee('--color-brand-500: #123456', false);
        $this->actingAs($u->fresh())->get('/dashboard?accent=custom&color=javascript')->assertOk()->assertSee('--color-brand-500: #ff3366', false);
    }
}
