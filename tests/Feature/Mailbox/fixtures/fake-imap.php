<?php
// Tiny scripted IMAP server for tests: php fake-imap.php <port-file>. Serves one mailbox with two messages, then exits.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
file_put_contents($argv[1], (string) parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT));
$msgs = [
    7 => "From: Ravi <ravi@example.com>\r\nTo: asha@example.org\r\nSubject: First {curly}\r\nMessage-ID: <m7@example.com>\r\n\r\nBody one\r\n",
    9 => "From: Neha <neha@example.com>\r\nTo: asha@example.org\r\nSubject: Second\r\nMessage-ID: <m9@example.com>\r\n\r\nBody two\r\n",
];
for ($n = 0; $n < 2; $n++) { // the sync connects once; a second run connects again
    $c = @stream_socket_accept($server, 20);
    if (!$c) {
        break;
    }
    fwrite($c, "* OK fake ready\r\n");
    $folder = 'INBOX';
    while (($line = fgets($c)) !== false) {
        [$tag, $cmd] = explode(' ', trim($line), 2) + [1 => ''];
        $u = strtoupper($cmd);
        if (str_starts_with($u, 'LOGIN')) {
            fwrite($c, str_contains($cmd, '"secret"') ? "$tag OK logged in\r\n" : "$tag NO bad password\r\n");
        } elseif (str_starts_with($u, 'LIST')) {
            fwrite($c, "* LIST (\\HasNoChildren) \"/\" \"INBOX\"\r\n* LIST (\\HasNoChildren \\Sent) \"/\" \"Sent Items\"\r\n$tag OK\r\n");
        } elseif (str_starts_with($u, 'SELECT')) {
            $folder = str_contains($cmd, 'Sent') ? 'Sent' : 'INBOX';
            $exists = $folder === 'INBOX' ? 2 : 0;
            fwrite($c, "* $exists EXISTS\r\n* OK [UIDVALIDITY 555] ok\r\n* OK [UIDNEXT 10] ok\r\n$tag OK [READ-WRITE] done\r\n");
        } elseif (str_starts_with($u, 'UID SEARCH')) {
            $all = array_keys($msgs);
            if (preg_match('/UID (\d+):\*/i', $cmd, $m)) {
                $all = array_values(array_filter($all, fn ($x) => $x >= (int) $m[1]));
                $all = $all ?: [9]; // like real servers: n:* always returns the highest uid
            }
            fwrite($c, '* SEARCH ' . implode(' ', $all) . "\r\n$tag OK\r\n");
        } elseif (preg_match('/^UID FETCH ([\d,]+) \(UID RFC822\.SIZE FLAGS\)/i', $cmd, $m)) {
            foreach (explode(',', $m[1]) as $i => $uid) {
                fwrite($c, '* ' . ($i + 1) . " FETCH (UID $uid RFC822.SIZE " . strlen($msgs[$uid]) . ' FLAGS (' . ($uid == 7 ? '\\Seen' : '') . "))\r\n");
            }
            fwrite($c, "$tag OK\r\n");
        } elseif (preg_match('/^UID FETCH (\d+) \(UID BODY\.PEEK\[\]\)/i', $cmd, $m)) {
            $raw = $msgs[(int) $m[1]];
            fwrite($c, "* 1 FETCH (UID {$m[1]} BODY[] {" . strlen($raw) . "}\r\n" . $raw . ")\r\n$tag OK\r\n");
        } elseif (str_starts_with($u, 'LOGOUT')) {
            fwrite($c, "* BYE\r\n$tag OK\r\n");
            break;
        } else {
            fwrite($c, "$tag BAD unknown\r\n");
        }
    }
    fclose($c);
}
