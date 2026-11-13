<?php

namespace Modules\Contacts\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Modules\Authentication\Models\User;
use Modules\Contacts\Exceptions\AlreadyContactsException;
use Modules\Contacts\Exceptions\ContactRequestNotPendingException;
use Modules\Contacts\Exceptions\DuplicateContactRequestException;
use Modules\Contacts\Exceptions\UserBlockedException;
use Modules\Contacts\Models\BlockedUser;
use Modules\Contacts\Models\ContactRequest;
use Modules\Contacts\Repositories\ContactRepository;
use Modules\Core\Services\PushNotificationService;

class ContactService
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly PushNotificationService $push,
    ) {
    }

    /**
     * SRS FR-021/FR-022 - RAS-2 API7.
     */
    public function searchUsers(User $searcher, string $query): Collection
    {
        return $this->contacts->searchUsers($searcher, $query);
    }

    /**
     * SRS FR-023/FR-027 - RAS-2 API8.
     */
    public function sendRequest(User $sender, int $receiverId, ?string $message): ContactRequest
    {
        if ($sender->id === $receiverId) {
            throw new InvalidArgumentException('You cannot send a contact request to yourself.');
        }

        if ($this->contacts->isBlockedEitherWay($sender->id, $receiverId)) {
            throw new UserBlockedException;
        }

        if ($this->contacts->findExistingContact($sender->id, $receiverId)) {
            throw new AlreadyContactsException;
        }

        if ($this->contacts->findPendingRequestBetween($sender->id, $receiverId)) {
            throw new DuplicateContactRequestException;
        }

        $request = $this->contacts->createRequest($sender->id, $receiverId, $message);

        $receiver = User::with('preferences')->find($receiverId);
        if ($receiver?->preferences?->notify_contact_requests ?? true) {
            $this->push->sendToUser($receiver, $sender->full_name, 'sent you a contact request', ['type' => 'contact_request']);
        }

        return $request;
    }

    /**
     * RAS-2 API9. Recipient-only ownership is enforced by
     * ContactRequestPolicy::respond() at the controller layer; pending
     * state is a domain rule checked here.
     */
    public function acceptRequest(ContactRequest $request): void
    {
        $this->assertPending($request);
        $this->contacts->acceptRequest($request);
    }

    /**
     * RAS-2 API10. See acceptRequest() note on the authorization/state split.
     */
    public function rejectRequest(ContactRequest $request): void
    {
        $this->assertPending($request);
        $this->contacts->rejectRequest($request);
    }

    /**
     * Sender-only ownership is enforced by ContactRequestPolicy::cancel().
     */
    public function cancelRequest(ContactRequest $request): void
    {
        $this->assertPending($request);
        $this->contacts->cancelRequest($request);
    }

    /**
     * RAS-2 API11.
     */
    public function listContacts(User $user): LengthAwarePaginator
    {
        return $this->contacts->listContacts($user->id);
    }

    /**
     * RAS-2 API12.
     */
    public function removeContact(User $user, int $contactUserId): void
    {
        $this->contacts->removeContact($user->id, $contactUserId);
    }

    /**
     * RAS-2 API15/API16 - FR-024.
     */
    public function setFavorite(User $user, int $contactUserId, bool $favorite): void
    {
        $this->contacts->setFavorite($user->id, $contactUserId, $favorite);
    }

    /**
     * RAS-2 API13 - FR-029.
     */
    public function block(User $user, int $targetUserId, ?string $reason): BlockedUser
    {
        if ($user->id === $targetUserId) {
            throw new InvalidArgumentException('You cannot block yourself.');
        }

        return $this->contacts->block($user->id, $targetUserId, $reason);
    }

    /**
     * RAS-2 API14.
     */
    public function unblock(User $user, int $targetUserId): void
    {
        $this->contacts->unblock($user->id, $targetUserId);
    }

    public function listBlocked(User $user): Collection
    {
        return $this->contacts->listBlocked($user->id);
    }

    public function isBlockedEitherWay(int $userA, int $userB): bool
    {
        return $this->contacts->isBlockedEitherWay($userA, $userB);
    }

    /**
     * Tells the UI whether "Add contact" should even be offered for a
     * given search result - avoids a user re-sending a request that
     * would just bounce off DuplicateContactRequestException/
     * AlreadyContactsException.
     */
    public function relationshipStatus(int $userId, int $otherUserId): string
    {
        if ($this->contacts->findExistingContact($userId, $otherUserId)) {
            return 'contact';
        }

        $pending = $this->contacts->findPendingRequestBetween($userId, $otherUserId);

        if ($pending) {
            return $pending->sender_id === $userId ? 'pending_sent' : 'pending_received';
        }

        return 'none';
    }

    private function assertPending(ContactRequest $request): void
    {
        if (! $request->isPending()) {
            throw new ContactRequestNotPendingException;
        }
    }
}
