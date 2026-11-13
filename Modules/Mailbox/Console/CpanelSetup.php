<?php

namespace Modules\Mailbox\Console;

use Illuminate\Console\Command;
use Modules\Mailbox\Services\CpanelMail;

/** Makes sure the mail domain's catch-all is piped to Meet (runs on every update and daily). */
class CpanelSetup extends Command
{
    protected $signature = 'mail:cpanel-setup';

    protected $description = 'Point the cPanel catch-all of MEET_MAIL_DOMAIN to Meet (mailbox:pipe)';

    public function handle(): int
    {
        $r = CpanelMail::ensure();
        $this->line(strtoupper($r['status']) . ': ' . $r['message']);
        foreach ($r['aliases'] ?? [] as $d => $a) {
            $this->line(strtoupper($a['status']) . " ($d): " . $a['message']);
        }

        return self::SUCCESS; // never fails an update; the status file / log tell what is missing
    }
}
