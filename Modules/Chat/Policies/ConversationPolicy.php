<?php

namespace Modules\Chat\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;

class ConversationPolicy
{
    use HandlesAuthorization;

    /**
     * Membership gates viewing, archiving, marking read, typing, drafts,
     * and settings - all conversation actions require being a member.
     */
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->members()->where('user_id', $user->id)->exists();
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
