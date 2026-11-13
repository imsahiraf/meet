<?php

namespace Tests\Feature\Web;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Tests\TestCase;

/** Profile photo and cover photo from the Profile page. */
class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_change_and_remove_the_profile_and_cover_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['full_name' => 'Ada Lovelace', 'status' => 'active', 'email_verified_at' => now()]);
        (new \Modules\Authentication\Listeners\CreateDefaultUserRecords)->handle(new \Modules\Authentication\Events\UserRegistered($user, '000000'));

        $this->actingAs($user, 'web')->post(route('profile.photo.update', 'avatar'), ['photo' => UploadedFile::fake()->image('me.jpg', 300, 300)])->assertSessionHasNoErrors();
        $first = $user->refresh()->profile_photo;
        $this->assertNotNull($first);
        $this->assertSame($first, $user->profile->avatar);
        $this->assertStringStartsWith('/storage/avatars/', $first);
        Storage::disk('public')->assertExists(substr($first, strlen('/storage/')));
        $this->actingAs($user, 'web')->get(route('profile.info'))->assertOk()->assertSee($first, false);

        $this->actingAs($user, 'web')->post(route('profile.photo.update', 'avatar'), ['photo' => UploadedFile::fake()->image('new.png', 200, 200)])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing(substr($first, strlen('/storage/'))); // the old file is gone

        $this->actingAs($user, 'web')->post(route('profile.photo.update', 'cover'), ['photo' => UploadedFile::fake()->image('cover.webp', 1200, 400)])->assertSessionHasNoErrors();
        $this->assertNotNull($user->refresh()->profile->cover_photo);

        $this->actingAs($user, 'web')->post(route('profile.photo.update', 'avatar'), ['photo' => UploadedFile::fake()->create('virus.php', 10, 'text/x-php')])->assertSessionHasErrors('photo');
        $this->actingAs($user, 'web')->post(route('profile.photo.update', 'avatar'), ['photo' => UploadedFile::fake()->image('tiny.jpg', 10, 10)])->assertSessionHasErrors('photo');

        $this->actingAs($user, 'web')->delete(route('profile.photo.delete', 'avatar'))->assertSessionHas('status', 'Profile photo removed.');
        $this->assertNull($user->refresh()->profile_photo);
        $this->assertNull($user->profile->avatar);
        $this->assertCount(1, Storage::disk('public')->allFiles()); // only the cover is left
    }

    public function test_the_photo_follows_the_privacy_setting(): void
    {
        $mk = function (string $name) {
            $u = User::factory()->create(['full_name' => $name, 'status' => 'active', 'email_verified_at' => now(), 'profile_photo' => '/storage/avatars/' . strtolower($name) . '.jpg']);
            (new \Modules\Authentication\Listeners\CreateDefaultUserRecords)->handle(new \Modules\Authentication\Events\UserRegistered($u, '000000'));

            return $u->refresh();
        };
        $owner = $mk('Owner');
        $friend = $mk('Friend');
        $stranger = $mk('Stranger');
        \Modules\Contacts\Models\Contact::create(['user_id' => $owner->id, 'contact_user_id' => $friend->id]);

        $this->assertSame(url('/storage/avatars/owner.jpg'), $owner->photoFor($stranger)); // everyone (default)
        $owner->privacy()->update(['profile_photo_visibility' => 'contacts']);
        $owner->refresh();
        $this->assertSame(url('/storage/avatars/owner.jpg'), $owner->photoFor($friend));
        $this->assertNull($owner->photoFor($stranger));
        $this->assertNull($owner->photoFor(null));
        $owner->privacy()->update(['profile_photo_visibility' => 'nobody']);
        $owner->refresh();
        $this->assertNull($owner->photoFor($friend));
        $this->assertSame(url('/storage/avatars/owner.jpg'), $owner->photoFor($owner)); // you always see your own
    }
}
