<?php

namespace Modules\Contacts\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Modules\Contacts\Models\ContactRequest;
use Modules\Contacts\Policies\ContactRequestPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        ContactRequest::class => ContactRequestPolicy::class,
    ];
}
