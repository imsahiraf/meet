<?php

namespace Modules\Authentication\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Authentication\Events\EmailVerified;
use Modules\Authentication\Events\PasswordResetRequested;
use Modules\Authentication\Events\UserLoggedIn;
use Modules\Authentication\Events\UserRegistered;
use Modules\Authentication\Listeners\CreateDefaultUserRecords;
use Modules\Authentication\Listeners\SendPasswordResetMail;
use Modules\Authentication\Listeners\SendRegistrationOtpMail;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * Note: auto-discovery (`shouldDiscoverEvents`) only applies to the
     * framework's own base EventServiceProvider class, never to
     * subclasses like this one - so module event/listener pairs must be
     * registered explicitly here.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        UserRegistered::class => [
            SendRegistrationOtpMail::class,
            CreateDefaultUserRecords::class,
        ],
        EmailVerified::class => [],
        PasswordResetRequested::class => [
            SendPasswordResetMail::class,
        ],
        UserLoggedIn::class => [],
    ];
}
