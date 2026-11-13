<?php

namespace Modules\Mailbox\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Mailbox\Console\CpanelSetup;
use Modules\Mailbox\Console\MailPipe;
use Modules\Mailbox\Jobs\SyncMailJob;
use Nwidart\Modules\Support\ModuleServiceProvider;

class MailboxServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Mailbox';

    protected string $nameLower = 'mailbox';

    protected array $commands = [MailPipe::class, CpanelSetup::class, \Modules\Mailbox\Console\MailDue::class];

    protected array $providers = [RouteServiceProvider::class];

    /** Google and IMAP sync every hour (free for every user). */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->job(new SyncMailJob())->hourly()->withoutOverlapping();
        // keep the catch-all of the mail domain pointed at Meet (set up by code, no hand work in cPanel)
        $schedule->command('mail:cpanel-setup')->dailyAt('03:10')->withoutOverlapping();
        $schedule->command('mailbox:due')->everyMinute()->withoutOverlapping(5); // send later / undo send / snooze
    }
}
