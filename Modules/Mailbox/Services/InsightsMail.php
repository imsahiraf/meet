<?php

namespace Modules\Mailbox\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailMessage;
use RuntimeException;

/**
 * Company mailboxes (the organization's own domain, kept in Insights by Meet Mail Hub) shown in Meet as accounts of
 * kind "insights": new mail comes in through Insights' mail API (hourly with the other accounts, and on "Sync now"),
 * and mail written here is sent by Insights from that address. The opposite of Insights' MeetMail.
 * Every call carries a short signed token: {iss: "meet", aud: "insights", sub: <Insights login>, scope: "mail"}, signed
 * with the shared INSIGHTS_SSO_SECRET. The Insights login is the linked one (Settings > Insights by Meet) or the Meet e-mail.
 */
class InsightsMail
{
    public static function configured(): bool
    {
        return (string) config('services.insights.sso_secret') !== '' && (string) config('services.insights.url') !== '';
    }

    public static function login(User $user): string
    {
        return strtolower((string) (DB::table('insights_links')->where('user_id', $user->id)->value('insights_email') ?: $user->email));
    }

    private static function token(User $user): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $body = $b64(json_encode(['iss' => 'meet', 'aud' => 'insights', 'sub' => self::login($user), 'scope' => 'mail',
            'iat' => time(), 'exp' => time() + 300, 'jti' => Str::random(24)], JSON_UNESCAPED_SLASHES));

        return $body . '.' . $b64(hash_hmac('sha256', $body, (string) config('services.insights.sso_secret'), true));
    }

    private static function call(User $user, string $method, string $path, array $data = []): array
    {
        if (!self::configured()) {
            throw new RuntimeException(__('Insights by Meet is not connected on this server.'));
        }
        $url = rtrim((string) config('services.insights.url'), '/') . '/api/meet/mail/' . ltrim($path, '/');
        $r = Http::withToken(self::token($user))->acceptJson()->timeout(40)->{$method}($url, $data ?: null);
        if ($r->status() === 404) {
            throw new RuntimeException(__('Insights by Meet has no login for :e.', ['e' => self::login($user)]));
        }
        if ($r->status() === 422) {
            throw new RuntimeException((string) ($r->json('error') ?: __('Insights by Meet did not accept this.')));
        }
        if (!$r->successful()) {
            throw new RuntimeException(__('Insights by Meet answered :s.', ['s' => $r->status()]));
        }

        return (array) $r->json();
    }

    /** Adds every company mailbox of the person's Insights login as an account here. Returns the accounts. */
    public static function connect(User $user): array
    {
        $out = [];
        foreach ((array) (self::call($user, 'get', 'accounts')['accounts'] ?? []) as $row) {
            $email = strtolower((string) ($row['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($row['id'])) {
                continue;
            }
            $a = MailAccount::firstOrNew(['user_id' => $user->id, 'email' => $email]);
            if ($a->exists && $a->kind !== 'insights') {
                continue; // the same address is already here another way (IMAP) - keep that one
            }
            $state = (array) ($a->sync_state ?? []);
            $a->forceFill(['kind' => 'insights', 'provider' => 'insights', 'display_name' => $a->display_name ?: ($row['name'] ?? null),
                'sync_state' => ['insights_id' => (int) $row['id'], 'insights_last' => (int) ($state['insights_last'] ?? 0)],
                'is_default' => $a->exists ? $a->is_default : !MailAccount::where('user_id', $user->id)->exists()])->save();
            $out[] = $a;
        }

        return $out;
    }

    /** New mail of the mailbox since the last run. Returns how many messages came in. */
    public static function sync(MailAccount $account): int
    {
        $user = User::find($account->user_id);
        $state = (array) ($account->sync_state ?? []);
        if (!$user || empty($state['insights_id'])) {
            return 0;
        }
        $after = (int) ($state['insights_last'] ?? 0);
        $n = 0;
        try {
            for ($round = 0; $round < 20; $round++) {
                $page = self::call($user, 'get', 'messages', ['account' => (int) $state['insights_id'], 'after' => $after, 'limit' => 25]);
                foreach ((array) ($page['messages'] ?? []) as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    if (!$id || MailMessage::where('account_id', $account->id)->where('remote_folder', 'insights')->where('remote_uid', $id)->exists()) {
                        continue;
                    }
                    $folder = in_array($row['folder'] ?? '', ['inbox', 'sent', 'archive', 'spam'], true) ? $row['folder'] : 'inbox';
                    $why = null;
                    if (Mailbox::ingest($account, (string) base64_decode((string) ($row['raw'] ?? '')), $folder,
                        ['remote_folder' => 'insights', 'remote_uid' => $id, 'seen' => (bool) ($row['seen'] ?? false), 'starred' => (bool) ($row['starred'] ?? false)], $why)) {
                        $n++;
                    }
                }
                $last = (int) ($page['last'] ?? $after);
                if ($last <= $after || count((array) ($page['messages'] ?? [])) === 0) {
                    break;
                }
                $after = $last;
            }
            $state['insights_last'] = $after;
            $account->forceFill(['sync_state' => $state, 'last_sync_at' => now(), 'last_error' => null])->save();
        } catch (\Throwable $e) {
            $state['insights_last'] = $after;
            $account->forceFill(['sync_state' => $state, 'last_error' => mb_substr($e->getMessage(), 0, 300)])->save();
        }

        return $n;
    }

    /** Sends through Insights from the company address and keeps the copy in Sent here. */
    public static function send(MailAccount $account, array $to, array $cc, array $bcc, string $subject, string $html, array $attachments = []): MailMessage
    {
        $user = User::findOrFail($account->user_id);
        $r = self::call($user, 'post', 'send', ['account' => (int) (($account->sync_state ?? [])['insights_id'] ?? 0), 'to' => $to, 'cc' => $cc, 'bcc' => $bcc,
            'subject' => $subject, 'html' => $html,
            'attachments' => array_map(fn ($a) => ['filename' => $a['filename'], 'mime' => $a['mime'] ?? null, 'content' => base64_encode($a['content'])], $attachments)]);
        $copy = Mailbox::ingest($account, (string) base64_decode((string) ($r['raw'] ?? '')), 'sent', ['remote_folder' => 'insights', 'remote_uid' => (int) ($r['id'] ?? 0), 'seen' => true]);
        foreach (array_merge($to, $cc) as $addr) {
            \Modules\Mailbox\Models\MailContact::firstOrCreate(['user_id' => $account->user_id, 'email' => strtolower($addr)], ['source' => 'sent']);
        }

        return $copy ?? MailMessage::where('account_id', $account->id)->where('folder', 'sent')->latest('id')->firstOrFail();
    }
}
