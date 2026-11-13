<?php

namespace Modules\Chat\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Message;

class MessagePolicy
{
    use HandlesAuthorization;

    /**
     * Viewing/reacting/replying/forwarding/starring/pinning all require
     * membership in the message's conversation.
     */
    public function view(User $user, Message $message): bool
    {
        return $message->conversation->members()->where('user_id', $user->id)->exists();
    }

    public function interact(User $user, Message $message): bool
    {
        return $this->view($user, $message);
    }

    /**
     * FR-041 - only the sender may edit.
     */
    public function update(User $user, Message $message): bool
    {
        return $message->sender_id === $user->id;
    }

    /**
     * FR-042 "Delete for Me" only requires membership.
     */
    public function delete(User $user, Message $message): bool
    {
        return $this->view($user, $message);
    }

    /**
     * FR-042 "Delete for Everyone" requires being the sender.
     */
    public function deleteForEveryone(User $user, Message $message): bool
    {
        return $message->sender_id === $user->id;
    }
}
