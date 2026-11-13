<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Authentication\Models\User;

/**
 * Undo path for SeedLoadTestUsersCommand - see LOAD_TESTING.md. The
 * '@mail.meet' domain is the sole, deliberate marker: every load-test
 * user (and only a load-test user) has one, so this is a precise,
 * safe bulk delete rather than a guess.
 */
class CleanupLoadTestUsersCommand extends Command
{
    protected $signature = 'load-test:cleanup-users {--force : Skip the confirmation prompt}';

    protected $description = 'Permanently deletes every load-test user (email ending @mail.meet) and their related rows';

    public function handle(): void
    {
        $count = User::withTrashed()->where('email', 'like', '%@mail.meet')->count();

        if ($count === 0) {
            $this->info('No load-test users found.');

            return;
        }

        if (! $this->option('force') && ! $this->confirm("This will permanently delete {$count} load-test user(s) and everything cascading from them (profiles, messages, contacts, etc.). Continue?")) {
            $this->info('Cancelled.');

            return;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        User::withTrashed()->where('email', 'like', '%@mail.meet')
            ->chunkById(1000, function ($users) use ($bar) {
                foreach ($users as $user) {
                    // forceDelete(), not delete() - these were never real
                    // users and shouldn't linger as soft-deleted rows;
                    // the users table FKs (profile/preference/privacy/
                    // presence/contacts/etc.) cascade on delete.
                    $user->forceDelete();
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("Deleted {$count} load-test user(s).");
    }
}
