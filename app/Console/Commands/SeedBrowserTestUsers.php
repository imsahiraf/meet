<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\ActivityLog;
use Modules\Admin\Models\Admin;
use Modules\Admin\Models\AuditLog;
use Modules\Admin\Models\BrandingSetting;
use Modules\Admin\Models\FeatureFlag;
use Modules\Admin\Models\Setting;
use Modules\Authentication\Events\UserRegistered;
use Modules\Authentication\Listeners\CreateDefaultUserRecords;
use Modules\Authentication\Models\User;
use Modules\Calls\Models\Call;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationMember;
use Modules\Contacts\Models\BlockedUser;
use Modules\Contacts\Models\Contact;
use Modules\Contacts\Models\ContactRequest;
use Modules\Contacts\Models\FavoriteContact;
use Modules\Groups\Models\Group;
use Modules\Meetings\Models\Meeting;

/**
 * Creates (or resets) a fixed set of verified users for the Puppeteer
 * browser-test harness at tests/Browser/. Guarded against production
 * since it creates known-password accounts.
 */
class SeedBrowserTestUsers extends Command
{
    protected $signature = 'test:seed-browser-users';

    protected $description = 'Seed the fixed set of verified users used by tests/Browser/*.js';

    public const PASSWORD = 'BrowserTest1!';

    public const USERS = [
        ['email' => 'browser.alice@example.test', 'full_name' => 'Browser Alice', 'phone' => '+910000000101'],
        ['email' => 'browser.bob@example.test', 'full_name' => 'Browser Bob', 'phone' => '+910000000102'],
        ['email' => 'browser.carol@example.test', 'full_name' => 'Browser Carol', 'phone' => '+910000000103'],
        ['email' => 'browser.admin@example.test', 'full_name' => 'Browser Admin', 'phone' => '+910000000104'],
    ];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to seed known-password test users in production.');

            return self::FAILURE;
        }

        $existingIds = User::whereIn('email', array_column(self::USERS, 'email'))->pluck('id');

        if ($existingIds->isNotEmpty()) {
            $this->resetRelatedData($existingIds->all());
        }

        foreach (self::USERS as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'full_name' => $data['full_name'],
                    'phone' => $data['phone'],
                    'password' => bcrypt(self::PASSWORD),
                    'status' => 'active',
                ],
            );
            $user->forceFill(['email_verified_at' => now()])->save();

            // Bypassing real registration (this command inserts the
            // users row directly) previously meant these accounts never
            // got a profile/preferences/privacy/presence row at all -
            // unlike any real registered user, so tests exercising
            // Settings-page prefill behavior were silently checking
            // against permanently-null data. firstOrCreate inside the
            // listener makes this safe to call on every run.
            (new CreateDefaultUserRecords)->handle(new UserRegistered($user, '000000'));

            if ($data['email'] === 'browser.admin@example.test') {
                Admin::updateOrCreate(['user_id' => $user->id], ['admin_level' => 'super', 'is_super_admin' => true]);
            }

            $this->info("Ready: {$data['email']} (id={$user->id})");
        }

        $this->resetAdminPanelState();

        return self::SUCCESS;
    }

    /**
     * Admin Panel browser tests toggle global settings/feature flags/
     * branding - reset them to their seeded defaults so repeated runs
     * are idempotent, same reasoning as resetRelatedData() below.
     */
    private function resetAdminPanelState(): void
    {
        BrandingSetting::current()->update(['app_name' => 'Meet', 'theme_color' => '#4f46e5']);
        Setting::where('setting_key', 'maintenance_mode_enabled')->update(['setting_value' => '0']);
        FeatureFlag::query()->update(['is_enabled' => true]);
        AuditLog::query()->delete();
        ActivityLog::query()->delete();
        \Modules\Admin\Models\Role::where('is_system', false)->delete();
    }

    /**
     * Browser tests re-run against the same fixed users many times over
     * (unlike PHPUnit's RefreshDatabase, this dev database persists
     * between runs) - without a reset, state that has a business-rule
     * ceiling (e.g. max pinned messages per conversation) eventually
     * breaks unrelated later runs. Deleting each Conversation cascades
     * to its messages/pins/stars/reactions/settings (all FK
     * cascadeOnDelete on conversation_id), which cleans up the vast
     * majority of Chat/Groups/Meetings state in one step.
     */
    private function resetRelatedData(array $userIds): void
    {
        self::resetFor($userIds);
    }

    /** Also used by meet:demo-world for its demo accounts (only the given users' own data). */
    public static function resetFor(array $userIds): void
    {
        Conversation::whereHas('members', fn ($q) => $q->whereIn('user_id', $userIds))->get()
            ->each(fn (Conversation $conversation) => $conversation->delete());

        ConversationMember::whereIn('user_id', $userIds)->delete();

        Group::whereIn('owner_id', $userIds)->get()->each(fn (Group $group) => $group->delete());

        Call::where(fn ($q) => $q->whereIn('caller_id', $userIds)->orWhereIn('receiver_id', $userIds))->delete();

        Meeting::whereIn('organizer_id', $userIds)->get()->each(fn (Meeting $meeting) => $meeting->delete());

        ContactRequest::where(fn ($q) => $q->whereIn('sender_id', $userIds)->orWhereIn('receiver_id', $userIds))->delete();
        Contact::where(fn ($q) => $q->whereIn('user_id', $userIds)->orWhereIn('contact_user_id', $userIds))->delete();
        BlockedUser::where(fn ($q) => $q->whereIn('user_id', $userIds)->orWhereIn('blocked_user_id', $userIds))->delete();
        FavoriteContact::whereIn('user_id', $userIds)->delete();

        // A 2FA/passkey browser test that fails before reaching its own
        // cleanup step (disable 2FA / remove the passkey) otherwise
        // leaves that fixture account permanently unable to log in via
        // the plain password-only loginAs() helper every other test
        // file uses - a single failed run in one file then cascades
        // into unrelated failures everywhere else that account is
        // reused, which is exactly what happened here before this was
        // added.
        DB::table('two_factor_secrets')->whereIn('user_id', $userIds)->delete();
        DB::table('two_factor_challenges')->whereIn('user_id', $userIds)->delete();
        DB::table('trusted_devices')->whereIn('user_id', $userIds)->delete();
        DB::table('passkeys')->whereIn('user_id', $userIds)->delete();
    }
}
