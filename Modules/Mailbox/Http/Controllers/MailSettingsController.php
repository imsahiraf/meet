<?php

namespace Modules\Mailbox\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Mailbox\Jobs\SyncMailJob;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailFilter;
use Modules\Mailbox\Models\MailLabel;
use Modules\Mailbox\Models\MailMessage;
use Modules\Mailbox\Models\MailSetting;
use Modules\Mailbox\Services\GoogleApi;
use Modules\Mailbox\Services\ImapClient;
use Modules\Mailbox\Services\ImapSync;
use Modules\Mailbox\Services\Mailbox;

class MailSettingsController extends Controller
{
    private function account(int $id): MailAccount
    {
        return MailAccount::where('user_id', Auth::id())->findOrFail($id);
    }

    public function index()
    {
        $uid = Auth::id();

        return view('mailbox::settings', [
            'accounts' => MailAccount::where('user_id', $uid)->orderByDesc('is_default')->get(),
            'settings' => MailSetting::for($uid), 'quota' => Mailbox::quota($uid),
            'filters' => MailFilter::where('user_id', $uid)->get(), 'groups' => \Modules\Mailbox\Models\MailContactGroup::where('user_id', $uid)->orderBy('name')->get(),
            'delegates' => \Modules\Authentication\Models\User::whereIn('id', \Illuminate\Support\Facades\DB::table('mail_delegates')->where('owner_id', $uid)->pluck('delegate_id'))->get(['id', 'full_name', 'email']), 'labels' => MailLabel::where('user_id', $uid)->orderBy('name')->get(),
            'presets' => config('mailbox.presets'), 'domain' => (string) config('mailbox.domain'), 'googleReady' => GoogleApi::configured(),
            'insightsReady' => \Modules\Mailbox\Services\InsightsMail::configured(), 'insightsLogin' => \Modules\Mailbox\Services\InsightsMail::login(Auth::user()),
            'unread' => collect(), 'drafts' => 0, 'folder' => 'settings', 'server' => Auth::user()->admin ? self::server() : null,
        ]);
    }

    /** Admins: is the catch-all of our mail domains piped to Meet, and when did mail last come in through it. */
    private static function server(): array
    {
        $last = MailMessage::where('folder', 'inbox')->whereIn('account_id', MailAccount::where('kind', 'local')->select('id'))->max('created_at');

        return ['status' => \Modules\Mailbox\Services\CpanelMail::status(), 'aliases' => \Modules\Mailbox\Services\Addresses::aliasDomains(),
            'suffix' => \Modules\Mailbox\Services\Addresses::suffix(), 'last' => $last ? \Illuminate\Support\Carbon::parse($last) : null,
            'token' => (string) config('services.cpanel.token') !== ''];
    }

    /** Admins: set the catch-all now instead of waiting for the daily run. */
    public function serverSetup()
    {
        abort_unless(Auth::user()->admin, 403);
        $r = \Modules\Mailbox\Services\CpanelMail::ensure();

        return back()->with('status', $r['message']);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'signature' => 'nullable|string|max:5000', 'vacation_on' => 'nullable|boolean', 'vacation_subject' => 'nullable|string|max:190',
            'vacation_body' => 'nullable|string|max:5000', 'vacation_until' => 'nullable|date', 'drive_archive' => 'nullable|boolean', 'contacts_sync' => 'nullable|boolean',
            'undo_seconds' => 'nullable|integer|in:0,10,20,30', 'conversations' => 'nullable|boolean', 'forward_to' => 'nullable|email|max:190',
        ]);
        $domain = strtolower((string) config('mailbox.domain'));
        if (!empty($data['forward_to']) && $domain !== '' && str_ends_with(strtolower($data['forward_to']), '@' . $domain)) {
            return back()->withErrors(['forward_to' => __('Use an address outside :d (for example your Gmail), or the mail would go round in a circle.', ['d' => $domain])])->withInput();
        }
        $s = MailSetting::for(Auth::id());
        $s->fill([
            'signature' => $data['signature'] ?? null, 'vacation_on' => (bool) ($data['vacation_on'] ?? false), 'vacation_subject' => $data['vacation_subject'] ?? null,
            'vacation_body' => $data['vacation_body'] ?? null, 'vacation_until' => $data['vacation_until'] ?? null,
            'drive_archive' => (bool) ($data['drive_archive'] ?? false), 'contacts_sync' => (bool) ($data['contacts_sync'] ?? false),
            'undo_seconds' => (int) ($data['undo_seconds'] ?? 0), 'conversations' => (bool) ($data['conversations'] ?? false),
            'forward_to' => $data['forward_to'] ?? null,
        ])->save();

        return back()->with('status', __('Settings saved.'));
    }

    /** The free Meet address: name@<MEET_MAIL_DOMAIN>, one per user. */
    public function createAddress(Request $request)
    {
        abort_if(\Modules\Mailbox\Services\Addresses::domain() === '', 404);
        $data = $request->validate(['local' => ['required', 'string', 'max:40']]);
        $account = \Modules\Mailbox\Services\Addresses::create(Auth::user(), $data['local']);

        return redirect()->route('mail.index')->with('status', __('Your address :a is ready.', ['a' => $account->email]));
    }

    /** Add Gmail / Yahoo / Rediffmail / Zoho / Outlook / any IMAP account; the login is tested before it is saved. */
    public function addAccount(Request $request)
    {
        $data = $request->validate([
            'provider' => 'required|string|in:' . implode(',', array_keys(config('mailbox.presets'))),
            'email' => 'required|email|max:190', 'password' => 'required|string|max:300', 'display_name' => 'nullable|string|max:120', 'username' => 'nullable|string|max:190',
            'imap_host' => 'nullable|string|max:190', 'imap_port' => 'nullable|integer|min:1|max:65535', 'imap_security' => 'nullable|in:ssl,tls,none',
            'smtp_host' => 'nullable|string|max:190', 'smtp_port' => 'nullable|integer|min:1|max:65535', 'smtp_security' => 'nullable|in:ssl,tls,none',
        ]);
        $p = config('mailbox.presets')[$data['provider']];
        $cfg = [
            'imap_host' => $data['imap_host'] ?: $p['imap'][0], 'imap_port' => $data['imap_port'] ?: $p['imap'][1], 'imap_security' => $data['imap_security'] ?: $p['imap'][2],
            'smtp_host' => $data['smtp_host'] ?: $p['smtp'][0], 'smtp_port' => $data['smtp_port'] ?: $p['smtp'][1], 'smtp_security' => $data['smtp_security'] ?: $p['smtp'][2],
        ];
        $cfg['imap_host'] = Mailbox::host((string) $cfg['imap_host']);
        $cfg['smtp_host'] = Mailbox::host((string) $cfg['smtp_host']);
        if ($cfg['imap_host'] === '' || $cfg['smtp_host'] === '' || !preg_match('/^[a-z0-9.-]+$/i', $cfg['imap_host'] . $cfg['smtp_host'])) {
            throw ValidationException::withMessages(['imap_host' => __('Enter the IMAP and SMTP server names.')]);
        }
        $email = strtolower($data['email']);
        if (MailAccount::where('user_id', Auth::id())->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('This account is already added.')]);
        }
        try {
            $imap = (new ImapClient($cfg['imap_host'], (int) $cfg['imap_port'], $cfg['imap_security'], 20))->connect();
            $imap->login($data['username'] ?: $email, $data['password']);
            $imap->logout();
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['password' => __('Could not sign in: :e', ['e' => mb_substr($e->getMessage(), 0, 200)])]);
        }
        $account = MailAccount::create($cfg + ['user_id' => Auth::id(), 'kind' => 'imap', 'provider' => $data['provider'], 'email' => $email,
            'display_name' => $data['display_name'] ?: Auth::user()->full_name, 'username' => $data['username'] ?: null, 'secret' => $data['password'],
            'is_default' => !MailAccount::where('user_id', Auth::id())->exists()]);
        dispatch(fn () => ImapSync::run($account))->afterResponse();

        return redirect()->route('mail.index', ['account' => $account->id])->with('status', __(':e added. Mail arrives in a minute.', ['e' => $email]));
    }

    public function syncAccount(int $id)
    {
        $a = $this->account($id);
        $n = match ($a->kind) {
            'imap', 'google' => ImapSync::run($a),
            'insights' => \Modules\Mailbox\Services\InsightsMail::sync($a),
            default => 0,
        };
        $a->refresh();

        return back()->with('status', $a->last_error ? __('Sync failed: :e', ['e' => $a->last_error]) : __(':n new messages.', ['n' => $n]));
    }

    /** Adds the company mailboxes of the person's Meet Insights login (and reads their mail right away). */
    public function insightsConnect()
    {
        try {
            $accounts = \Modules\Mailbox\Services\InsightsMail::connect(Auth::user());
        } catch (\Throwable $e) {
            return back()->withErrors(['insights' => $e->getMessage()]);
        }
        if (!$accounts) {
            return back()->withErrors(['insights' => __('Your Meet Insights login has no company mailbox yet. Ask your organization admin to give you an address in Mail Hub.')]);
        }
        $n = 0;
        foreach ($accounts as $a) {
            $n += \Modules\Mailbox\Services\InsightsMail::sync($a);
        }

        return back()->with('status', trans_choice(':c company mailbox added|:c company mailboxes added', count($accounts), ['c' => count($accounts)]) . ' ' . __(':n messages.', ['n' => $n]));
    }

    public function defaultAccount(int $id)
    {
        $a = $this->account($id);
        MailAccount::where('user_id', Auth::id())->update(['is_default' => false]);
        $a->forceFill(['is_default' => true])->save();

        return back()->with('status', __('Default address changed.'));
    }

    public function removeAccount(int $id)
    {
        $a = $this->account($id);
        foreach (MailMessage::where('account_id', $a->id)->with('attachments')->get() as $m) {
            Mailbox::purge($m);
        }
        $a->delete();

        return redirect()->route('mail.settings')->with('status', __('Account removed from Meet (nothing was deleted at the provider).'));
    }

    public function addFilter(Request $request)
    {
        $data = $request->validate(['field' => 'required|in:from,to,subject,body,any,attachment', 'contains' => 'required|string|max:190',
            'action' => 'required|in:label,folder,star,read', 'value' => 'nullable|string|max:60']);
        MailFilter::create($data + ['user_id' => Auth::id()]);

        return back()->with('status', __('Filter added. It applies to new mail.'));
    }

    /** Share my mailbox with a colleague (their Meet sign-in e-mail): they may read and send from it until I take it back. */
    public function addDelegate(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|max:190']);
        $who = \Modules\Authentication\Models\User::where('email', strtolower(trim($data['email'])))->first();
        if (!$who || $who->id === Auth::id()) {
            throw ValidationException::withMessages(['email' => __('No other Meet account signs in with this e-mail.')]);
        }
        \Illuminate\Support\Facades\DB::table('mail_delegates')->updateOrInsert(['owner_id' => Auth::id(), 'delegate_id' => $who->id], ['updated_at' => now(), 'created_at' => now()]);

        return back()->with('status', __(':n can now open your mailbox.', ['n' => $who->full_name]));
    }

    public function removeDelegate(int $id)
    {
        \Illuminate\Support\Facades\DB::table('mail_delegates')->where('owner_id', Auth::id())->where('delegate_id', $id)->delete();

        return back()->with('status', __('Sharing stopped.'));
    }

    public function addGroup(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'emails' => 'required|string|max:10000']);
        $emails = array_values(array_unique(array_filter(array_map(fn ($x) => strtolower(trim($x)), preg_split('/[\s,;]+/', $data['emails'])))));
        $bad = array_filter($emails, fn ($e) => !filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($bad || !$emails || count($emails) > 200) {
            throw ValidationException::withMessages(['emails' => $bad ? __('":a" is not an e-mail address.', ['a' => reset($bad)]) : __('Add 1 to 200 addresses.')]);
        }
        \Modules\Mailbox\Models\MailContactGroup::updateOrCreate(['user_id' => Auth::id(), 'name' => trim($data['name'])], ['emails' => implode(', ', $emails)]);

        return back()->with('status', __('Group saved.'));
    }

    public function removeGroup(int $id)
    {
        \Modules\Mailbox\Models\MailContactGroup::where('user_id', Auth::id())->whereKey($id)->delete();

        return back();
    }

    public function removeFilter(int $id)
    {
        MailFilter::where('user_id', Auth::id())->whereKey($id)->delete();

        return back();
    }

    public function addLabel(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:60', 'color' => ['nullable', 'regex:/^#[0-9a-f]{6}$/i']]);
        MailLabel::firstOrCreate(['user_id' => Auth::id(), 'name' => $data['name']], ['color' => $data['color'] ?? '#6366f1']);

        return back()->with('status', __('Label added.'));
    }

    public function removeLabel(int $id)
    {
        MailLabel::where('user_id', Auth::id())->whereKey($id)->delete();

        return back();
    }

    // ---------------- Google ----------------

    public function googleConnect(Request $request)
    {
        abort_unless(GoogleApi::configured(), 404);
        $state = Str::random(40);
        $request->session()->put('mail_google_state', $state);

        return redirect()->away(GoogleApi::authUrl($state));
    }

    public function googleCallback(Request $request)
    {
        $state = $request->session()->pull('mail_google_state');
        if (!$state || !hash_equals($state, (string) $request->query('state')) || !$request->query('code')) {
            return redirect()->route('mail.settings')->withErrors(['google' => __('Google sign-in was cancelled.')]);
        }
        try {
            [$token, $email] = GoogleApi::exchange((string) $request->query('code'));
        } catch (\Throwable $e) {
            return redirect()->route('mail.settings')->withErrors(['google' => $e->getMessage()]);
        }
        $s = MailSetting::for(Auth::id());
        $old = $s->google_token ?? [];
        $token['refresh_token'] ??= $old['refresh_token'] ?? null; // Google sends it only on the first consent
        $s->fill(['google_token' => $token, 'google_email' => $email, 'google_error' => null])->save();
        if ($email) {
            MailAccount::firstOrCreate(['user_id' => Auth::id(), 'email' => $email], ['kind' => 'google', 'provider' => 'gmail', 'display_name' => Auth::user()->full_name,
                'is_default' => !MailAccount::where('user_id', Auth::id())->exists()]);
        }
        $uid = Auth::id();
        dispatch(fn () => (new SyncMailJob($uid))->handle())->afterResponse();

        return redirect()->route('mail.settings')->with('status', __('Google connected: Gmail, Drive and Contacts sync every hour.'));
    }

    public function googleSync()
    {
        (new SyncMailJob(Auth::id()))->handle();
        $s = MailSetting::for(Auth::id());

        return back()->with('status', $s->google_error ? __('Google sync failed: :e', ['e' => $s->google_error]) : __('Synced with Google.'));
    }

    public function googleDisconnect()
    {
        $s = MailSetting::for(Auth::id());
        if ($email = $s->google_email) {
            if ($a = MailAccount::where('user_id', Auth::id())->where('kind', 'google')->where('email', $email)->first()) {
                foreach (MailMessage::where('account_id', $a->id)->with('attachments')->get() as $m) {
                    Mailbox::purge($m);
                }
                $a->delete();
            }
        }
        $s->fill(['google_token' => null, 'google_email' => null, 'drive_folder_id' => $s->drive_folder_id])->save();

        return back()->with('status', __('Google disconnected. Mail already moved to Drive stays in your Drive.'));
    }
}
