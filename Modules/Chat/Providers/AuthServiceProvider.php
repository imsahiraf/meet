<?php

namespace Modules\Chat\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Chat\Policies\ConversationPolicy;
use Modules\Chat\Policies\MessagePolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Conversation::class => ConversationPolicy::class,
        Message::class => MessagePolicy::class,
    ];
}
