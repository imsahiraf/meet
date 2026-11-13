<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Admin\Jobs\CleanupRetentionJob;
use Modules\Admin\Jobs\ScheduledArchiveJob;
use Modules\Admin\Jobs\SeedDailyLoadTestUsersJob;
use Modules\Authentication\Jobs\CleanupExpiredOtpsJob;
use Modules\Calls\Jobs\MarkMissedCallsJob;
use Modules\Chat\Jobs\CleanupExpiredDisappearingMessagesJob;
use Modules\Chat\Jobs\CleanupStaleTypingStatusJob;
use Modules\Core\Jobs\CleanupStalePushSubscriptionsJob;
use Modules\Groups\Jobs\CleanupExpiredInvitesJob;
use Modules\Meetings\Jobs\CleanupExpiredGuestSessionsJob;
use Modules\Meetings\Jobs\EndInactiveMeetingsJob;
use Modules\Meetings\Jobs\SendCalendarRemindersJob;
use Modules\Media\Jobs\CleanupOrphanedMediaJob;
use Modules\User\Jobs\MarkStalePresenceOfflineJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// SRS Ch18 - scheduled cleanup jobs. Runs via cPanel's cron-triggered
// `php artisan schedule:run` (see DEP/roadmap Phase 8).
Schedule::job(new CleanupExpiredOtpsJob)->hourly();
Schedule::job(new CleanupStaleTypingStatusJob)->everyFiveMinutes();
Schedule::job(new CleanupExpiredDisappearingMessagesJob)->everyFiveMinutes();
Schedule::job(new CleanupExpiredInvitesJob)->hourly();
Schedule::job(new MarkStalePresenceOfflineJob)->everyMinute();
Schedule::job(new MarkMissedCallsJob)->everyMinute();
Schedule::job(new SendCalendarRemindersJob)->everyMinute();
Schedule::job(new CleanupExpiredGuestSessionsJob)->everyMinute();
Schedule::job(new EndInactiveMeetingsJob)->everyFiveMinutes();
Schedule::job(new CleanupRetentionJob)->daily();
Schedule::job(new CleanupOrphanedMediaJob)->everyFifteenMinutes();
Schedule::job(new ScheduledArchiveJob)->daily();
Schedule::job(new CleanupStalePushSubscriptionsJob)->daily();
// P2 demo world: only the demo accounts, only once they were made (php artisan meet:demo-world)
Schedule::command('meet:demo-world --reset --if-made')->dailyAt('03:20')->withoutOverlapping(60);

// Load-test data generator - see LOAD_TESTING.md. Self-disables after
// 7 daily runs (tracked via a Setting counter, not a date), so this
// line can just stay here permanently without manual removal.
Schedule::job(new SeedDailyLoadTestUsersJob)->daily();

