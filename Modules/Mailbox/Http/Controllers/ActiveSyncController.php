<?php

namespace Modules\Mailbox\Http\Controllers;

use Modules\Authentication\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Mailbox\ActiveSync\Server;
use Modules\Mailbox\Models\EasDevice;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Models\MailAppPassword;
use Throwable;

/**
 * Phone mail apps over Exchange ActiveSync: https://<server>/Microsoft-Server-ActiveSync, user name = the mail address,
 * password = an app password made in Mail > Settings > Phone mail apps. Works on shared hosting (plain HTTPS through PHP).
 */
class ActiveSyncController extends Controller
{
    private function headers(): array
    {
        return ['MS-Server-ActiveSync' => '14.1', 'MS-ASProtocolVersions' => Server::VERSIONS, 'MS-ASProtocolCommands' => Server::COMMANDS,
            'Cache-Control' => 'private', 'X-Robots-Tag' => 'noindex'];
    }

    public function options()
    {
        return response('', 200, $this->headers() + ['Allow' => 'OPTIONS,POST', 'Public' => 'OPTIONS,POST']);
    }

    public function handle(Request $request)
    {
        $q = $this->query($request);
        [$user, $account, $password] = $this->authenticate($request, $q['User'] ?? null);
        if (!$user) {
            return response('', 401, ['WWW-Authenticate' => 'Basic realm="ActiveSync"'] + $this->headers());
        }
        $deviceId = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($q['DeviceId'] ?? '')), 0, 64);
        $cmd = (string) ($q['Cmd'] ?? '');
        if ($deviceId === '' || $cmd === '') {
            return response('', 400, $this->headers());
        }
        $device = EasDevice::firstOrCreate(['user_id' => $user->id, 'device_id' => $deviceId], ['device_type' => substr((string) ($q['DeviceType'] ?? ''), 0, 64)]);
        $device->forceFill(['account_id' => $account?->id, 'app_password_id' => $password->id, 'last_seen_at' => now(),
            'user_agent' => substr((string) $request->userAgent(), 0, 190)])->save();
        $password->forceFill(['last_used_at' => now(), 'last_ip' => $request->ip()])->save();
        Auth::setUser($user);
        $version = (string) ($request->header('MS-ASProtocolVersion') ?: ($q['ProtocolVersion'] ?? '14.1'));
        $version = in_array($version, explode(',', Server::VERSIONS), true) ? $version : '14.1';
        try {
            [$status, $body, $type] = (new Server($user, $account, $device, $version))->handle($cmd, (string) $request->getContent(), $q);
        } catch (Throwable $e) {
            report($e);

            return response('', 500, $this->headers());
        }

        return response($body, $status, $this->headers() + ['Content-Type' => $type]);
    }

    /** Phones that do not find the server by themselves ask the mail domain's autodiscover address. */
    public function autodiscover(Request $request)
    {
        $email = '';
        if (preg_match('#<EMailAddress>([^<]{3,190})</EMailAddress>#i', (string) $request->getContent(), $m)) {
            $email = e(trim($m[1]));
        }
        $url = e(url('/Microsoft-Server-ActiveSync'));
        $xml = '<?xml version="1.0" encoding="utf-8"?><Autodiscover xmlns="http://schemas.microsoft.com/exchange/autodiscover/responseschema/2006">'
            . '<Response xmlns="http://schemas.microsoft.com/exchange/autodiscover/mobilesync/responseschema/2006"><Culture>en:us</Culture>'
            . '<User><EMailAddress>' . $email . '</EMailAddress></User><Action><Settings>'
            . '<Server><Type>MobileSync</Type><Url>' . $url . '</Url><Name>' . $url . '</Name></Server>'
            . '</Settings></Action></Response></Autodiscover>';

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    /** The query: plain (?Cmd=Sync&User=..&DeviceId=..) or the compact base64 form some phones use. */
    private function query(Request $request): array
    {
        if ($request->query('Cmd')) {
            return $request->query();
        }
        $raw = base64_decode((string) $request->getQueryString(), true);
        if ($raw === false || strlen($raw) < 4) {
            return [];
        }
        $cmds = [0 => 'Sync', 1 => 'SendMail', 2 => 'SmartForward', 3 => 'SmartReply', 4 => 'GetAttachment', 9 => 'FolderSync', 10 => 'FolderCreate', 11 => 'FolderDelete',
            12 => 'FolderUpdate', 13 => 'MoveItems', 14 => 'GetItemEstimate', 15 => 'MeetingResponse', 16 => 'Search', 17 => 'Settings', 18 => 'Ping', 19 => 'ItemOperations',
            20 => 'Provision', 21 => 'ResolveRecipients', 22 => 'ValidateCert'];
        $pos = 0;
        $take = function (int $n) use ($raw, &$pos) {
            $s = substr($raw, $pos, $n);
            $pos += $n;

            return $s;
        };
        $version = ord($take(1));
        $q = ['ProtocolVersion' => sprintf('%d.%d', intdiv($version, 10), $version % 10), 'Cmd' => $cmds[ord($take(1))] ?? ''];
        $take(2); // locale
        $q['DeviceId'] = bin2hex($take(ord($take(1))));
        $take(ord($take(1))); // policy key
        $q['DeviceType'] = $take(ord($take(1)));
        $tags = [0 => 'AttachmentName', 1 => 'CollectionId', 3 => 'ItemId', 4 => 'LongId', 6 => 'Occurrence', 7 => 'Options', 8 => 'User'];
        while ($pos < strlen($raw)) {
            $tag = ord($take(1));
            $val = $take(ord($take(1)));
            if (isset($tags[$tag])) {
                $q[$tags[$tag]] = $val;
            }
        }

        return $q;
    }

    /** @return array{0: ?User, 1: ?MailAccount, 2: ?MailAppPassword} */
    private function authenticate(Request $request, ?string $hint): array
    {
        $name = (string) $request->getUser();
        $pass = (string) $request->getPassword();
        if ($name === '' && preg_match('/^Basic\s+(.+)$/i', (string) $request->header('Authorization'), $m)) {
            [$name, $pass] = array_pad(explode(':', (string) base64_decode($m[1]), 2), 2, '');
        }
        $name = strtolower(trim(str_contains($name, '\\') ? substr(strrchr($name, '\\'), 1) : $name));
        $limit = 'eas|' . $request->ip() . '|' . $name;
        if ($name === '' || $pass === '' || RateLimiter::tooManyAttempts($limit, 10)) {
            return [null, null, null];
        }
        $account = MailAccount::where('email', $name)->orderBy('id')->first();
        $user = $account ? User::find($account->user_id) : User::where('email', $name)->first();
        $password = $user ? MailAppPassword::check($user->id, $pass) : null;
        if (!$user || !$password || !$this->entitled($user)) {
            RateLimiter::hit($limit, 600);

            return [null, null, null];
        }
        RateLimiter::clear($limit);

        return [$user, $account && (int) $account->user_id === (int) $user->id ? $account : null, $password];
    }

    /** In Meet every active account has its mail (ported from Insights by Meet 2026-11-10, where it follows the organization's plan). */
    private function entitled(User $user): bool
    {
        return ($user->status ?? 'active') === 'active'; // Meet keeps the account state in "status"
    }
}
