<?php

namespace Modules\Chat\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Authentication\Models\User;
use Modules\Chat\Exceptions\NotConversationMemberException;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationMember;
use Modules\Chat\Models\Draft;
use Modules\Chat\Repositories\ConversationRepository;
use Modules\Contacts\Exceptions\UserBlockedException;
use Modules\Contacts\Services\ContactService;

class ConversationService
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly ContactService $contacts,
    ) {
    }

    /**
     * FR-036 / RAS-2 API17 - reuse an existing private conversation where applicable.
     */
    public function startPrivateConversation(User $user, int $participantId): Conversation
    {
        if ($user->id === $participantId) {
            throw new \InvalidArgumentException('You cannot start a conversation with yourself.');
        }

        if ($this->contacts->isBlockedEitherWay($user->id, $participantId)) {
            throw new UserBlockedException('You cannot message this user.');
        }

        $existing = $this->conversations->findExistingPrivateConversation($user->id, $participantId);
        if ($existing) {
            // Deleting a chat (leave()) only hides it from this user's own
            // list - deliberately re-starting it must undo that, the same
            // way GroupService clears left_at on rejoin, or the reused
            // conversation would silently stay invisible in the list.
            $this->conversations->clearLeftAt($existing->id, $user->id);

            return $existing;
        }

        return $this->conversations->createPrivateConversation($user, $participantId);
    }

    /**
     * RAS-2 API18.
     */
    public function listForUser(User $user, ?bool $archived = null): LengthAwarePaginator
    {
        $this->conversations->markAllDelivered($user->id);

        return $this->conversations->listForUser($user->id, $archived);
    }

    public function unreadCount(Conversation $conversation, User $user): int
    {
        return $this->conversations->unreadCount($conversation->id, $user->id);
    }

    public function totalUnreadCount(User $user): int
    {
        return $this->conversations->totalUnreadCount($user->id);
    }

    /**
     * RAS-2 API19 - verifies membership before returning conversation details.
     */
    public function show(User $user, Conversation $conversation): Conversation
    {
        $this->assertMember($user, $conversation);

        return $conversation->load(['members.user.profile', 'members.user.presence', 'lastMessage']);
    }

    /**
     * RAS-2 API20.
     */
    public function archive(User $user, Conversation $conversation, bool $archived = true): void
    {
        $membership = $this->assertMember($user, $conversation);
        $membership->update(['is_archived' => $archived]);
    }

    /**
     * RAS-2 API21 - removes the conversation from the user's own list
     * only; the conversation persists for the other member(s), per the
     * documented retention policy (full purge is an Admin/Phase-7 job).
     */
    public function leave(User $user, Conversation $conversation): void
    {
        $membership = $this->assertMember($user, $conversation);
        $membership->update(['left_at' => now()]);
    }

    /**
     * RAS-2 API32.
     */
    public function markRead(User $user, Conversation $conversation): void
    {
        $this->assertMember($user, $conversation);
        $this->conversations->markRead($conversation, $user);
    }

    /**
     * RAS-2 API33.
     */
    public function setTyping(User $user, Conversation $conversation, bool $isTyping, string $status = 'typing'): void
    {
        $this->assertMember($user, $conversation);

        if ($isTyping) {
            $this->conversations->setTyping($conversation->id, $user->id, $status);
        } else {
            $this->conversations->clearTyping($conversation->id, $user->id);
        }
    }

    public function activeTypingUsers(Conversation $conversation, User $exceptUser): Collection
    {
        return $this->conversations->activeTypingUsers(
            $conversation->id,
            $exceptUser->id,
            config('chat.typing_timeout_seconds'),
        );
    }

    /**
     * RAS-2 API34.
     */
    public function saveDraft(User $user, Conversation $conversation, string $body): Draft
    {
        $this->assertMember($user, $conversation);

        return $this->conversations->saveDraft($conversation->id, $user->id, $body);
    }

    /**
     * RAS-2 API35.
     */
    public function deleteDraft(User $user, Conversation $conversation): void
    {
        $this->assertMember($user, $conversation);
        $this->conversations->deleteDraft($conversation->id, $user->id);
    }

    /**
     * RAS-2 API36 - mute, wallpaper, archive, notifications.
     */
    public function updateSettings(User $user, Conversation $conversation, array $data): ChatSetting
    {
        $membership = $this->assertMember($user, $conversation);

        return $this->conversations->updateSettings($membership, $data);
    }

    /**
     * Disappearing messages - conversation-wide, so any member can set
     * it (matching real WhatsApp), not just an owner/admin.
     */
    public function updateDisappearingMessages(User $user, Conversation $conversation, ?int $seconds): Conversation
    {
        $this->assertMember($user, $conversation);

        return $this->conversations->updateDisappearingMessages($conversation, $seconds);
    }

    public function assertMember(User $user, Conversation $conversation): ConversationMember
    {
        $membership = $this->conversations->getMembership($conversation->id, $user->id);

        if (! $membership) {
            throw new NotConversationMemberException;
        }

        return $membership;
    }
}
