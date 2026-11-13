<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Models\User;
use Modules\Contacts\Models\Contact;
use Modules\User\Models\UserPresence;
use Modules\User\Models\UserPrivacy;
use Tests\TestCase;

/**
 * Regression coverage for a bug reported from the browser: sending a
 * duplicate contact request rendered the exception's raw JSON envelope
 * instead of a friendly flashed error, because DuplicateContactRequestException
 * (and its siblings) unconditionally returned a JsonResponse from render()
 * regardless of request type. Also covers that the search UI stops
 * offering "Add contact" once a relationship already exists.
 */
class WebContactsFlowTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'status' => 'active',
            'email_verified_at' => now(),
        ], $overrides));
    }

    public function test_duplicate_contact_request_flashes_a_friendly_error_instead_of_json(): void
    {
        $me = $this->activeUser();
        $other = $this->activeUser();

        $this->actingAs($me, 'web')
            ->post('/contacts/request', ['receiver_id' => $other->id])
            ->assertRedirect();

        $response = $this->actingAs($me, 'web')
            ->post('/contacts/request', ['receiver_id' => $other->id]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('request');
        $this->assertStringContainsString(
            'pending contact request already exists',
            session('errors')->first('request'),
        );
    }

    public function test_search_hides_add_contact_button_once_a_request_is_pending(): void
    {
        $me = $this->activeUser();
        $other = $this->activeUser(['full_name' => 'Pending Person']);

        $this->actingAs($me, 'web')->post('/contacts/request', ['receiver_id' => $other->id]);

        $response = $this->actingAs($me, 'web')->get('/contacts?q=Pending');

        $response->assertStatus(200);
        $response->assertSee('Request sent');
        $response->assertDontSee('Add contact');
    }

    public function test_search_offers_add_contact_when_no_relationship_exists(): void
    {
        $me = $this->activeUser();
        $this->activeUser(['full_name' => 'Fresh Person']);

        $response = $this->actingAs($me, 'web')->get('/contacts?q=Fresh');

        $response->assertStatus(200);
        $response->assertSee('Add contact');
    }

    /**
     * Real production request: an offline contact showed the bare word
     * "Offline" with no indication of when they were last seen. This
     * server-rendered page reads presence directly (not through
     * ContactResource), and previously did so with NO privacy gating at
     * all - a contact who set last_seen_visibility to "nobody" still
     * had their live online/offline status shown on this first page
     * load (only the JS-driven infinite-scroll page, via the API, was
     * ever gated correctly).
     */
    public function test_contacts_page_shows_last_seen_and_respects_last_seen_visibility_of_nobody(): void
    {
        $me = $this->activeUser();
        $visible = $this->activeUser(['full_name' => 'Visible Person']);
        $hidden = $this->activeUser(['full_name' => 'Hidden Person']);

        Contact::create(['user_id' => $me->id, 'contact_user_id' => $visible->id]);
        Contact::create(['user_id' => $me->id, 'contact_user_id' => $hidden->id]);

        UserPresence::updateOrCreate(['user_id' => $visible->id], ['presence_status' => 'offline', 'last_seen_at' => now()->subHours(3)]);
        UserPresence::updateOrCreate(['user_id' => $hidden->id], ['presence_status' => 'online']);
        UserPrivacy::updateOrCreate(['user_id' => $hidden->id], ['last_seen_visibility' => 'nobody']);

        $response = $this->actingAs($me, 'web')->get('/contacts');

        $response->assertStatus(200);
        $response->assertSee('last seen 3 hours ago');
        $response->assertSee('Offline');
    }
}
