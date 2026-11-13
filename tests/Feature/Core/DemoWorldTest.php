<?php

namespace Tests\Feature\Core;

use App\Console\Commands\DemoWorld;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Meetings\Models\Meeting;
use Tests\TestCase;

/** P2 demo world: Meet demo accounts with sample chats, reset without touching anyone else. */
class DemoWorldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(storage_path('app/demo-world.json'));
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/demo-world.json'));
        parent::tearDown();
    }

    public function test_demo_accounts_are_made_with_chats_and_reset_leaves_real_people_alone(): void
    {
        $this->assertSame(0, Artisan::call('meet:demo-world', ['--if-made' => true]));
        $this->assertFalse(User::where('email', DemoWorld::email('asha'))->exists());

        $real = User::factory()->create(['email' => 'real.person@example.test']);
        $this->assertSame(0, Artisan::call('meet:demo-world'));
        $asha = User::where('email', DemoWorld::email('asha'))->firstOrFail();
        $this->assertNotNull($asha->email_verified_at);
        $this->assertSame(2, Conversation::whereHas('members', fn ($q) => $q->where('user_id', $asha->id))->where('type', '!=', 'meeting')->count());
        $this->assertSame(8, Message::where('body', '!=', '')->whereIn('sender_id', User::where('email', 'like', 'demo.%')->pluck('id'))->count());
        $this->assertSame(2, Meeting::where('organizer_id', $asha->id)->count());
        $this->assertFalse((bool) $asha->admin);

        $realChat = app(\Modules\Chat\Services\ConversationService::class)->startPrivateConversation($real, User::factory()->create()->id);
        $hash = $asha->fresh()->password;
        $this->assertSame(0, Artisan::call('meet:demo-world', ['--reset' => true]));
        $this->assertSame(2, Meeting::where('organizer_id', $asha->id)->count()); // made again, not doubled
        $this->assertTrue(Conversation::whereKey($realChat->id)->exists());
        $this->assertTrue(User::whereKey($real->id)->exists());
        $this->assertNotSame('', $hash);
        $this->assertSame(0, Artisan::call('meet:demo-world', ['--mail' => true]));
    }
}
