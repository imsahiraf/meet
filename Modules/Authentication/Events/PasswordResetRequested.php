<?php

namespace Modules\Authentication\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Authentication\Models\PasswordResetToken;
use Modules\Authentication\Models\User;

class PasswordResetRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly PasswordResetToken $resetToken,
    ) {
    }
}
