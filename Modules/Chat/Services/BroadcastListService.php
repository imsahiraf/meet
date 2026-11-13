<?php

namespace Modules\Chat\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\BroadcastList;
use Modules\Chat\Repositories\BroadcastListRepository;
use Modules\Contacts\Exceptions\UserBlockedException;

class BroadcastListService
{
    public function __construct(
        private readonly BroadcastListRepository $lists,
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {
    }

    public function create(User $owner, string $name, array $recipientIds): BroadcastList
    {
        return $this->lists->create($owner, $name, $recipientIds);
    }

    public function update(User $user, BroadcastList $list, string $name, array $recipientIds): BroadcastList
    {
        $this->assertOwner($user, $list);

        return $this->lists->update($list, $name, $recipientIds);
    }

    public function listForUser(User $user): Collection
    {
        return $this->lists->listForOwner($user->id);
    }

    public function show(User $user, BroadcastList $list): BroadcastList
    {
        $this->assertOwner($user, $list);

        return $list->load('recipients.user.profile');
    }

    public function delete(User $user, BroadcastList $list): void
    {
        $this->assertOwner($user, $list);
        $this->lists->delete($list);
    }

    /**
     * Fans out to N separate 1:1 conversations - each recipient sees
     * it as a normal DM from the sender, replies stay private per
     * conversation. A recipient the sender has since blocked/been
     * blocked by is silently skipped (not a fatal error for the whole
     * send) since that's exactly how a direct message to them would
     * already fail.
     */
    public function send(User $user, BroadcastList $list, array $data): int
    {
        $this->assertOwner($user, $list);

        $sent = 0;

        foreach ($list->recipients as $recipient) {
            try {
                $conversation = $this->conversations->startPrivateConversation($user, $recipient->user_id);
                $this->messages->send($user, $conversation, $data);
                $sent++;
            } catch (UserBlockedException) {
                continue;
            }
        }

        return $sent;
    }

    private function assertOwner(User $user, BroadcastList $list): void
    {
        if ($list->owner_id !== $user->id) {
            throw new AuthorizationException('Only the owner may manage this broadcast list.');
        }
    }
}
