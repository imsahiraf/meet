<?php

namespace Modules\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Modules\Admin\Models\Setting;

/**
 * Adds 10,000 more load-test users a day for 7 days after the initial
 * seed, then self-disables - see LOAD_TESTING.md. Tracked via a plain
 * Setting counter rather than a date comparison, so it's unaffected by
 * the job ever being skipped/delayed on a given day (it just runs 7
 * times total, whenever those 7 days happen to land).
 *
 * Two independent stop conditions, whichever comes first: this 7-day
 * counter, and the admin-configurable load_test_max_users cap enforced
 * inside SeedLoadTestUsersCommand itself (default 150,000) - if the
 * cap is already reached, the command just inserts zero regardless of
 * how many days remain, so nothing extra needs checking here.
 *
 * Deliberately NOT ShouldQueue - runs directly inside the scheduler
 * process, same reasoning as every other job in this app (see
 * RegistrationOtpNotification's docblock).
 */
class SeedDailyLoadTestUsersJob
{
    use Dispatchable, Queueable, SerializesModels;

    private const DAYS_RUN_SETTING_KEY = 'load_test_seed_days_run';

    private const MAX_DAYS = 7;

    private const USERS_PER_DAY = 10000;

    public function handle(): void
    {
        $daysRun = (int) (Setting::where('setting_key', self::DAYS_RUN_SETTING_KEY)->value('setting_value') ?? 0);

        if ($daysRun >= self::MAX_DAYS) {
            return;
        }

        Artisan::call('load-test:seed-users', ['count' => self::USERS_PER_DAY]);

        Setting::updateOrCreate(
            ['setting_key' => self::DAYS_RUN_SETTING_KEY],
            ['setting_value' => (string) ($daysRun + 1), 'category' => 'internal', 'data_type' => 'integer', 'is_editable' => false],
        );
    }
}
