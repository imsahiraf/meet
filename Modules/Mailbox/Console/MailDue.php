<?php

namespace Modules\Mailbox\Console;

use Illuminate\Console\Command;
use Modules\Mailbox\Services\Outbox;

/** Every minute: send scheduled mail that is due (send later, undo send) and bring snoozed mail back to the inbox. */
class MailDue extends Command
{
    protected $signature = 'mailbox:due';

    protected $description = 'Send scheduled mail that is due and wake snoozed mail';

    public function handle(): int
    {
        [$sent, $failed, $woken] = Outbox::run();
        if ($sent || $failed || $woken) {
            $this->line("sent $sent, failed $failed, back from snooze $woken");
        }

        return self::SUCCESS;
    }
}
