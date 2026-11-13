<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Authentication\Events\UserRegistered;
use Modules\Authentication\Listeners\CreateDefaultUserRecords;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\MessageService;
use Modules\Contacts\Models\Contact;
use Modules\Groups\Services\GroupService;
use Modules\Meetings\Services\MeetingService;

/**
 * Demo accounts of Meet on the live server (owner 2026-11-10, P2 demo world): a few people on the demo domain who know each
 * other, with a chat, a group and meetings, for showing customers and testing on production. No admin rights: the Admin panel
 * would show real people. Only these demo accounts are reset; everyone else is never touched.
 *   php artisan meet:demo-world            make what is missing (safe to repeat)
 *   php artisan meet:demo-world --reset    their chats, groups, meetings and contacts wiped and made again (nightly)
 *   php artisan meet:demo-world --mail     also e-mail the logins to the owner (deploy/update.php does this after an update)
 */
class DemoWorld extends Command
{
    protected $signature = 'meet:demo-world {--reset} {--mail} {--new-password} {--if-made : only when the demo accounts were made before}';

    protected $description = 'Make or reset the Meet demo accounts with sample chats, a group and meetings';

    public const PEOPLE = [
        'asha' => ['Asha Patil (Demo)', '+919990000101'],
        'ravi' => ['Ravi Kumar (Demo)', '+919990000102'],
        'neha' => ['Neha Sharma (Demo)', '+919990000103'],
        'imran' => ['Imran Shaikh (Demo)', '+919990000104'],
    ];

    public static function email(string $key): string
    {
        return "demo.$key@".config('app.demo_world.domain', 'demo.example.com');
    }

    private static function stateFile(): string
    {
        return storage_path('app/demo-world.json');
    }

    public function handle(): int
    {
        $state = is_file(self::stateFile()) ? (json_decode((string) file_get_contents(self::stateFile()), true) ?: []) : [];
        if ($this->option('if-made') && ! $state) {
            return self::SUCCESS;
        }
        $password = $this->option('new-password') ? null : ((string) config('app.demo_world.password', '') ?: null); // owner 2026-11-11: fixed Demo@meet1
        if ($password === null && ! $this->option('new-password') && ! empty($state['password'])) {
            try {
                $password = Crypt::decryptString($state['password']);
            } catch (\Throwable) {
            }
        }
        $password ??= 'Demo-'.Str::password(10, symbols: false);

        $existing = User::whereIn('email', array_map([self::class, 'email'], array_keys(self::PEOPLE)))->pluck('id')->all();
        if ($this->option('reset') && $existing) {
            SeedBrowserTestUsers::resetFor($existing);
            $this->line('Wiped the chats, groups, meetings and contacts of '.count($existing).' demo accounts');
        }

        $users = [];
        foreach (self::PEOPLE as $key => [$name, $phone]) {
            $user = User::where('email', self::email($key))->first();
            if (! $user && User::where('phone', $phone)->exists()) {
                $phone = null; // a real person has that number: the demo account goes without one
            }
            $user ??= new User(['email' => self::email($key)]);
            $user->forceFill(['full_name' => $name, 'phone' => $user->phone ?? $phone, 'password' => bcrypt($password), 'status' => 'active',
                'email_verified_at' => $user->email_verified_at ?? now()])->save();
            (new CreateDefaultUserRecords)->handle(new UserRegistered($user, '000000'));
            $users[$key] = $user;
        }

        try {
            $this->sample($users);
        } catch (\Throwable $e) {
            $this->warn('Sample chats: '.$e->getMessage());
        }

        file_put_contents(self::stateFile(), json_encode(['made_at' => now()->toDateTimeString(), 'password' => Crypt::encryptString($password)]));
        @chmod(self::stateFile(), 0600);
        $logins = array_map(fn ($k) => [self::PEOPLE[$k][0], self::email($k)], array_keys(self::PEOPLE));
        $this->table(['Demo person', 'E-mail'], $logins);
        $this->info('Password for every demo login: '.$password);
        if ($this->option('mail')) {
            $this->mail($logins, $password);
        }

        return self::SUCCESS;
    }

    /** Contacts between all of them, a private chat, a group and two meetings - only when they have none yet. */
    private function sample(array $u): void
    {
        foreach ($u as $a) {
            foreach ($u as $b) {
                if ($a->id !== $b->id) {
                    Contact::firstOrCreate(['user_id' => $a->id, 'contact_user_id' => $b->id]);
                }
            }
        }
        $hasChats = Conversation::whereHas('members', fn ($q) => $q->where('user_id', $u['asha']->id))->exists();
        if ($hasChats) {
            return;
        }
        $messages = app(MessageService::class);
        $chat = app(ConversationService::class)->startPrivateConversation($u['asha'], $u['ravi']->id);
        foreach ([['asha', 'Hi Ravi, did the new price list reach the shop?'], ['ravi', 'Yes, got it this morning. Sharing it with the team today.'],
            ['asha', 'Great. Can we do a quick video call at 4?'], ['ravi', 'Sure, send me the link.']] as [$who, $text]) {
            $messages->send($u[$who], $chat, ['message_type' => 'text', 'body' => $text]);
        }
        $group = app(GroupService::class)->create($u['asha'], 'Demo team', 'Sample group of the demo accounts', null, [$u['ravi']->id, $u['neha']->id, $u['imran']->id]);
        $conversation = Conversation::find($group->conversation_id);
        foreach ([['asha', 'Good morning team! Weekly review on Friday.'], ['neha', 'Noted. I will bring the sales numbers.'],
            ['imran', 'Stock count is done for this week.'], ['ravi', 'Thanks all.']] as [$who, $text]) {
            $messages->send($u[$who], $conversation, ['message_type' => 'text', 'body' => $text]);
        }
        $meetings = app(MeetingService::class);
        $tz = config('app.timezone', 'Asia/Kolkata');
        foreach ([['Weekly review', 2], ['Customer demo', 26]] as [$title, $hours]) {
            $m = $meetings->createScheduled($u['asha'], ['title' => $title, 'description' => 'Sample meeting of the demo accounts',
                'scheduled_start' => now($tz)->addHours($hours)->format('Y-m-d H:i'), 'scheduled_end' => now($tz)->addHours($hours + 1)->format('Y-m-d H:i'), 'timezone' => $tz]);
            foreach (['ravi', 'neha'] as $p) {
                $meetings->addDirectParticipant($m, $u[$p]);
            }
        }
    }

    private function mail(array $logins, string $password): void
    {
        $to = (string) config('app.demo_world.mail_to');
        $body = 'Meet demo accounts on '.url('/').' (version '.config('app.version', '').")\nPassword for all: $password\n\n"
            .implode("\n", array_map(fn ($l) => "  {$l[0]}: {$l[1]}", $logins))
            ."\n\nTheir chats, group and meetings are wiped and made again every night.";
        try {
            Mail::raw($body, fn ($m) => $m->to($to)->subject('Meet demo logins'));
            $this->line("Demo logins mailed to $to");
        } catch (\Throwable $e) {
            $this->warn('Mail could not be sent: '.$e->getMessage());
        }
    }
}
