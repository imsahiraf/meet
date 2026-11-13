<?php

namespace Modules\Mailbox\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Points the mail domain's catch-all (cPanel "Default Address") at Meet: every address that is not a real cPanel mailbox is
 * piped to `artisan mailbox:pipe`. Runs on every update and once a day, so nobody has to set it up by hand.
 *
 * cPanel is reached through its own API (UAPI):
 *   1. the `uapi` command of the account (works where PHP may run programs - no token needed), else
 *   2. https://<CPANEL_HOST>:2083/execute/... with CPANEL_USER + CPANEL_API_TOKEN (cPanel > Security > Manage API Tokens).
 * The result is kept in storage/app/cpanel-mail.json (shown in the logs and on the mail settings page of admins).
 */
class CpanelMail
{
    public const STATUS_FILE = 'cpanel-mail.json';

    /** The home folder of the hosting account (the cPanel pipe path is relative to it). */
    public static function home(): string
    {
        return rtrim((string) (getenv('HOME') ?: dirname(base_path(), 1)), '/');
    }

    /** The PHP that runs the pipe: cPanel's PHP 8.4 when it is there (Meet needs 8.4), else this PHP on the command line, else the default. */
    public static function phpBinary(): string
    {
        foreach (['/opt/cpanel/ea-php84/root/usr/bin/php', '/opt/cpanel/ea-php85/root/usr/bin/php'] as $p) {
            if (@is_executable($p)) {
                return $p;
            }
        }

        return (PHP_SAPI === 'cli' && PHP_BINARY) ? PHP_BINARY : '/usr/local/bin/php';
    }

    /**
     * Small PHP program in the home folder that cPanel pipes mail into (cPanel wants a program path, not a command line, and this
     * hosting has no shell / sh): its first line names PHP, then it runs "artisan mailbox:pipe" with the mail on its input.
     */
    public static function pipeScript(): string
    {
        $file = self::home() . '/meet-mail-pipe.php';
        $body = '#!' . self::phpBinary() . " -q\n<?php\n// made by Meet (mail:cpanel-setup): pipes one e-mail into Meet. cPanel > Email > Default Address > Pipe to a Program: meet-mail-pipe.php\n"
            . '$_SERVER[\'argv\'] = [\'artisan\', \'mailbox:pipe\'];' . "\n" . '$_SERVER[\'argc\'] = 2;' . "\n" . 'chdir(' . var_export(base_path(), true) . ");\n"
            . 'require ' . var_export(base_path('artisan'), true) . ";\n";
        if (@file_get_contents($file) !== $body) {
            if (@file_put_contents($file, $body) === false) {
                throw new RuntimeException("Cannot write $file");
            }
        }
        @chmod($file, 0755);

        return $file;
    }

    /**
     * The main domain decides the status; other domains of the same people (MEET_MAIL_ALIAS_DOMAINS, e.g. example.net) are set the same
     * way and reported under "aliases" (one that is not on this cPanel account does not stop the main one).
     *
     * @return array{status: string, message: string, domain: string, at: string, aliases?: array<string, array{status: string, message: string}>}
     */
    public static function ensure(): array
    {
        $domain = Addresses::domain();
        $result = ['domain' => $domain, 'at' => now()->toDateTimeString()];
        if ($domain === '') {
            return self::save($result + ['status' => 'off', 'message' => 'MEET_MAIL_DOMAIN is empty: own addresses are off.']);
        }
        try {
            $script = self::pipeScript();
        } catch (\Throwable $e) {
            return self::save($result + ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 400)]);
        }
        $result += self::point($domain, $script);
        foreach (Addresses::aliasDomains() as $alias) {
            $result['aliases'][$alias] = self::point($alias, $script);
        }

        return self::save($result);
    }

    /** @return array{status: string, message: string} */
    private static function point(string $domain, string $script): array
    {
        try {
            $current = self::call('Email', 'list_default_address', ['domain' => $domain]);
            $now = '';
            foreach ((array) ($current['data'] ?? []) as $row) {
                if (strcasecmp((string) ($row['domain'] ?? ''), $domain) === 0) {
                    $now = (string) ($row['defaultaddress'] ?? '');
                }
            }
            if (str_contains($now, basename($script))) {
                return ['status' => 'ok', 'message' => "Catch-all of $domain already goes to Meet."];
            }
            self::call('Email', 'set_default_address', ['domain' => $domain, 'fwdopt' => 'pipe', 'pipefwd' => $script]);

            return ['status' => 'ok', 'message' => "Catch-all of $domain now goes to Meet (was: " . ($now ?: 'not set') . ').'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => mb_substr($e->getMessage(), 0, 400)];
        }
    }

    /** One UAPI call through the account's uapi command or the token. Returns the decoded "result" block. */
    public static function call(string $module, string $function, array $params): array
    {
        $token = (string) config('services.cpanel.token');
        if ($token !== '') {
            $user = (string) (config('services.cpanel.user') ?: get_current_user());
            $url = 'https://' . config('services.cpanel.host', 'localhost') . ':' . (int) config('services.cpanel.port', 2083) . "/execute/$module/$function";
            $r = Http::withHeaders(['Authorization' => "cpanel $user:$token"])->withoutVerifying()->timeout(30)->get($url, $params);
            if (!$r->successful()) {
                throw new RuntimeException("cPanel API answered HTTP {$r->status()}.");
            }

            return self::check((array) $r->json());
        }
        if (self::canRun()) {
            $cmd = 'uapi --output=json ' . escapeshellarg($module) . ' ' . escapeshellarg($function);
            foreach ($params as $k => $v) {
                $cmd .= ' ' . escapeshellarg($k . '=' . $v);
            }
            $out = @shell_exec($cmd . ' 2>/dev/null');
            $json = json_decode((string) $out, true);
            if (is_array($json)) {
                return self::check((array) ($json['result'] ?? $json));
            }
        }
        throw new RuntimeException('cPanel cannot be reached from here: add CPANEL_API_TOKEN (cPanel > Security > Manage API Tokens) to .env.');
    }

    private static function check(array $result): array
    {
        $result = isset($result['result']) && is_array($result['result']) ? $result['result'] : $result;
        if ((int) ($result['status'] ?? 0) !== 1) {
            throw new RuntimeException('cPanel: ' . implode(' ', (array) ($result['errors'] ?? ['request failed'])));
        }

        return $result;
    }

    private static function canRun(): bool
    {
        if (!function_exists('shell_exec')) {
            return false;
        }
        $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('shell_exec', $off, true);
    }

    private static function save(array $r): array
    {
        @file_put_contents(storage_path('app/' . self::STATUS_FILE), json_encode($r, JSON_PRETTY_PRINT));
        if ($r['status'] === 'error') {
            \Illuminate\Support\Facades\Log::warning('mail:cpanel-setup: ' . $r['message']);
        }
        foreach ($r['aliases'] ?? [] as $d => $a) {
            if ($a['status'] === 'error') {
                \Illuminate\Support\Facades\Log::warning("mail:cpanel-setup ($d): " . $a['message']);
            }
        }

        return $r;
    }

    public static function status(): ?array
    {
        $j = json_decode((string) @file_get_contents(storage_path('app/' . self::STATUS_FILE)), true);

        return is_array($j) ? $j : null;
    }
}
