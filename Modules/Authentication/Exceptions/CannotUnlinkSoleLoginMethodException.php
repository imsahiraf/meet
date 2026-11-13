<?php

namespace Modules\Authentication\Exceptions;

use Exception;

class CannotUnlinkSoleLoginMethodException extends Exception
{
    public function __construct(string $message = "You have no password set - this is your only way to sign in, so it can't be unlinked. Set a password first.")
    {
        parent::__construct($message);
    }
}
