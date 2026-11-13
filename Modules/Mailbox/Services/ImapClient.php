<?php

namespace Modules\Mailbox\Services;

use RuntimeException;

/**
 * Minimal IMAP4rev1 client over PHP streams (no imap extension: PHP 8.4 dropped it and shared hosting rarely has it).
 * Enough for mailbox sync: LOGIN / AUTHENTICATE XOAUTH2, LIST, SELECT, UID SEARCH, UID FETCH, UID STORE, APPEND.
 */
class ImapClient
{
    private $stream = null;

    private int $tag = 0;

    public function __construct(private string $host, private int $port = 993, private string $security = 'ssl', private int $timeout = 30)
    {
    }

    public function connect(): self
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $this->host]]);
        $scheme = $this->security === 'ssl' ? 'ssl' : 'tcp';
        $this->stream = @stream_socket_client("$scheme://{$this->host}:{$this->port}", $errno, $err, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->stream) {
            throw new RuntimeException("Cannot reach {$this->host}:{$this->port} ($err)");
        }
        stream_set_timeout($this->stream, $this->timeout);
        $greeting = $this->line();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
            throw new RuntimeException('Unexpected greeting: ' . trim($greeting));
        }
        if ($this->security === 'tls') {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('STARTTLS failed');
            }
        }

        return $this;
    }

    public function login(string $user, string $password): void
    {
        $this->command('LOGIN ' . $this->quote($user) . ' ' . $this->quote($password), 'Login refused - check the e-mail / password (an app password may be needed)');
    }

    public function oauth(string $user, string $accessToken): void
    {
        $this->command('AUTHENTICATE XOAUTH2 ' . base64_encode("user=$user\x01auth=Bearer $accessToken\x01\x01"), 'Google refused the connection - connect Google again');
    }

    /** @return array<int, array{name: string, flags: string}> */
    public function folders(): array
    {
        $out = [];
        foreach ($this->command('LIST "" "*"')['lines'] as $line) {
            if (preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (.+)$/i', rtrim($line), $m)) {
                $out[] = ['name' => $this->unquote($m[2]), 'flags' => $m[1]];
            }
        }

        return $out;
    }

    /** Find a special folder (\Sent, \Trash ...) by its attribute, else by common names. */
    public function special(string $attr, array $names): ?string
    {
        $folders = $this->folders();
        foreach ($folders as $f) {
            if (stripos($f['flags'], '\\' . $attr) !== false) {
                return $f['name'];
            }
        }
        foreach ($folders as $f) {
            if (in_array(strtolower($f['name']), $names, true)) {
                return $f['name'];
            }
        }

        return null;
    }

    /** @return array{exists: int, uidvalidity: int, uidnext: int} */
    public function select(string $folder): array
    {
        $info = ['exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0];
        foreach ($this->command('SELECT ' . $this->quote($folder))['lines'] as $line) {
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) {
                $info['exists'] = (int) $m[1];
            } elseif (preg_match('/UIDVALIDITY (\d+)/i', $line, $m)) {
                $info['uidvalidity'] = (int) $m[1];
            } elseif (preg_match('/UIDNEXT (\d+)/i', $line, $m)) {
                $info['uidnext'] = (int) $m[1];
            }
        }

        return $info;
    }

    /** @return int[] */
    public function search(string $criteria): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH ' . $criteria)['lines'] as $line) {
            if (preg_match('/^\* SEARCH(.*)$/i', rtrim($line), $m)) {
                $uids = array_merge($uids, array_map('intval', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)));
            }
        }
        sort($uids);

        return $uids;
    }

    /** @return array<int, array{size: int, seen: bool}> uid => info */
    public function sizes(array $uids): array
    {
        if (!$uids) {
            return [];
        }
        $out = [];
        foreach ($this->command('UID FETCH ' . implode(',', $uids) . ' (UID RFC822.SIZE FLAGS)')['lines'] as $line) {
            if (preg_match('/UID (\d+)/i', $line, $u) && preg_match('/RFC822\.SIZE (\d+)/i', $line, $s)) {
                $out[(int) $u[1]] = ['size' => (int) $s[1], 'seen' => stripos($line, '\\Seen') !== false];
            }
        }

        return $out;
    }

    /** Whole message (RFC 822) without marking it read. */
    public function fetch(int $uid): ?string
    {
        $res = $this->command("UID FETCH $uid (UID BODY.PEEK[])");

        return $res['literals'][0] ?? null;
    }

    public function flag(int $uid, string $flag, bool $on = true): void
    {
        $this->command("UID STORE $uid " . ($on ? '+' : '-') . "FLAGS.SILENT ($flag)");
    }

    public function append(string $folder, string $raw, string $flags = '(\\Seen)'): void
    {
        $raw = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $raw);
        $tag = $this->nextTag();
        $this->write("$tag APPEND " . $this->quote($folder) . " $flags {" . strlen($raw) . "}\r\n");
        $cont = $this->line();
        if (!str_starts_with($cont, '+')) {
            throw new RuntimeException('APPEND refused: ' . trim($cont));
        }
        $this->write($raw . "\r\n");
        $this->finish($tag, 'Could not save to ' . $folder);
    }

    public function logout(): void
    {
        if ($this->stream) {
            try {
                $this->command('LOGOUT');
            } catch (\Throwable) {
                // closing anyway
            }
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    public function __destruct()
    {
        if ($this->stream) {
            @fclose($this->stream);
        }
    }

    // ---------------- protocol ----------------

    /** @return array{lines: string[], literals: string[]} */
    private function command(string $cmd, string $error = 'IMAP command failed'): array
    {
        $tag = $this->nextTag();
        $this->write("$tag $cmd\r\n");

        return $this->finish($tag, $error);
    }

    private function finish(string $tag, string $error): array
    {
        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->line();
            // a line ending in {n} announces n raw bytes, then the line continues
            while (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
                $literals[] = $this->bytes((int) $m[1]);
                $line = rtrim($line) . ' <literal> ' . $this->line();
            }
            if (str_starts_with($line, "$tag ")) {
                if (!preg_match("/^$tag OK/i", $line)) {
                    throw new RuntimeException($error . ': ' . trim(substr($line, strlen($tag) + 1)));
                }

                return ['lines' => $lines, 'literals' => $literals];
            }
            if (str_starts_with($line, '+') && str_contains($error, 'Google')) {
                // XOAUTH2 failure detail comes as a continuation: answer with an empty line to get the tagged NO
                $this->write("\r\n");
                continue;
            }
            $lines[] = $line;
        }
    }

    private function nextTag(): string
    {
        return 'Z' . str_pad((string) ++$this->tag, 4, '0', STR_PAD_LEFT);
    }

    private function write(string $data): void
    {
        if (!$this->stream || @fwrite($this->stream, $data) === false) {
            throw new RuntimeException('Connection to the mail server was lost');
        }
    }

    private function line(): string
    {
        $line = $this->stream ? fgets($this->stream, 1 << 20) : false;
        if ($line === false) {
            $meta = $this->stream ? stream_get_meta_data($this->stream) : [];
            throw new RuntimeException(!empty($meta['timed_out']) ? 'The mail server did not answer in time' : 'Connection to the mail server was lost');
        }

        return $line;
    }

    private function bytes(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->stream, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Connection to the mail server was lost');
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    private function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    private function unquote(string $s): string
    {
        $s = trim($s);

        return str_starts_with($s, '"') ? stripcslashes(substr($s, 1, -1)) : $s;
    }
}
