<?php

namespace Modules\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\PushSubscription;

/**
 * A device that hasn't been (re-)registered in over a year is almost
 * certainly gone for good - uninstalled, the browser's storage/service
 * worker cleared, or the device itself replaced - and FCM will just keep
 * failing silently against it forever otherwise. The client re-subscribes
 * on every page load while notifications are enabled (see
 * subscribeToPushNotifications() in the shared layout), which bumps
 * `updated_at` via PushNotificationService::subscribe()'s
 * updateOrCreate() - so a row this stale means genuinely a year with no
 * app open and permission granted, not just a quiet user.
 */
// Deliberately NOT ShouldQueue - see CleanupOrphanedMediaJob's docblock
// for why every cleanup job here runs directly in the scheduler process.
class CleanupStalePushSubscriptionsJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(): void
    {
        PushSubscription::where('updated_at', '<', now()->subYear())->delete();
    }
}
