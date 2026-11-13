<?php

namespace Modules\Mailbox\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Services\Addresses;
use Modules\Mailbox\Services\Mailbox;
use Modules\Mailbox\Services\MimeParser;

/**
 * cPanel "Pipe to a Program" target for the Meet mail domain (Documentation/MAIL_CPANEL_SETUP.md):
 *   |/usr/local/bin/php -q /home/<user>/meet/artisan mailbox:pipe
 * Reads one raw message from STDIN and delivers it to every Meet address it is for. Always exits 0 so the mail
 * server never bounces to the sender; unknown addresses and full mailboxes are written to the log.
 */
class MailPipe extends Command
{
    protected $signature = 'mailbox:pipe {--file= : read the message from a file (testing)} {--to=* : envelope recipient(s)}';

    protected $description = 'Deliver one raw e-mail from STDIN to Meet mailboxes';

    public function handle(): int
    {
        $max = (int) config('mailbox.max_message_mb', 20) * 1048576 + 1;
        $stream = $this->option('file') ? @fopen($this->option('file'), 'rb') : fopen('php://stdin', 'rb');
        if (!$stream) {
            return self::SUCCESS;
        }
        $raw = stream_get_contents($stream, $max) ?: '';
        while (!feof($stream)) {
            fread($stream, 65536); // drain: the MTA must not see a broken pipe
        }
        fclose($stream);
        $domain = strtolower((string) config('mailbox.domain'));
        if ($raw === '' || $domain === '') {
            return self::SUCCESS;
        }
        if (strlen($raw) >= $max) {
            Log::warning('mailbox:pipe message larger than the limit dropped');

            return self::SUCCESS;
        }
        $h = (new MimeParser())->parse(substr($raw, 0, 65536))['headers'];
        $candidates = $this->option('to') ?: MimeParser::addresses(implode(',', array_merge(
            $h['delivered-to'] ?? [], $h['x-original-to'] ?? [], $h['envelope-to'] ?? [], $h['to'] ?? [], $h['cc'] ?? []
        )));
        if ($env = getenv('RECIPIENT')) { // exim sets $RECIPIENT for pipe transports
            $candidates[] = strtolower($env);
        }
        $delivered = 0;
        $boxes = array_unique(array_filter(array_map(fn ($a) => Addresses::mailboxFor((string) $a), $candidates)));
        foreach ($boxes as $addr) {
            $account = MailAccount::where('kind', 'local')->where('email', $addr)->first();
            if (!$account) {
                Log::info("mailbox:pipe no mailbox for $addr");
                continue;
            }
            $msg = Mailbox::ingest($account, $raw, 'inbox', [], $why);
            if ($msg) {
                $delivered++;
                Mailbox::vacationReply($msg);
                Mailbox::forwardCopy($msg);
            } elseif ($why !== 'duplicate') {
                Log::warning("mailbox:pipe $addr: $why");
            }
        }
        $this->line("delivered $delivered");

        return self::SUCCESS;
    }
}
