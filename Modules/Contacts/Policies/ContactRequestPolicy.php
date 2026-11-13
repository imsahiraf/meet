<?php

namespace Modules\Contacts\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Authentication\Models\User;
use Modules\Contacts\Models\ContactRequest;

class ContactRequestPolicy
{
    use HandlesAuthorization;

    /**
     * RAS-2 API9/API10 - only the recipient may accept/reject.
     * Whether the request is still pending is a state rule, checked in
     * ContactService, not an ownership/authorization concern.
     */
    public function respond(User $user, ContactRequest $contactRequest): bool
    {
        return $contactRequest->receiver_id === $user->id;
    }

    /**
     * Only the sender may cancel their own request.
     */
    public function cancel(User $user, ContactRequest $contactRequest): bool
    {
        return $contactRequest->sender_id === $user->id;
    }
}
