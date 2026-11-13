<?php

namespace Modules\Chat\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationMember;
use Modules\Chat\Models\Draft;
use Modules\Chat\Models\Message;
use Modules\Chat\Models\MessageStatus;
use Modules\Chat\Models\ReadReceipt;
use Modules\Chat\Models\TypingStatus;

class ConversationRepository
{
    public function __construct(
        private readonly MessageRepository $messages,
    ) {
    }

    public function findExistingPrivateConversation(int $userA, int $userB): ?Conversation
    {
        return Conversation::where('type', 'private')
            ->whereHas('members', fn ($q) => $q->where('user_id', $userA))
            ->whereHas('members', fn ($q) => $q->where('user_id', $userB))
            ->first();
    }

    public function createPrivateConversation(User $creator, int $participantId): Conversation
    {
        return DB::transaction(function () use ($creator, $participantId) {
            $conversation = Conversation::create([
                'type' => 'private',
                'created_by' => $creator->id,
                'last_activity_at' => now(),
            ]);

            ConversationMember::create(['conversation_id' => $conversation->id, 'user_id' => $creator->id, 'role' => 'owner']);
            ConversationMember::create(['conversation_id' => $conversation->id, 'user_id' => $participantId, 'role' => 'member']);

            return $conversation;
        });
    }

    public function getMembership(int $conversationId, int $userId): ?ConversationMember
    {
        return ConversationMember::where('conversation_id', $conversationId)->where('user_id', $userId)->first();
    }

    public function clearLeftAt(int $conversationId, int $userId): void
    {
        ConversationMember::where('conversation_id', $conversationId)->where('user_id', $userId)->update(['left_at' => null]);
    }

    /**
     * paginate(), not simplePaginate() - scoped to one user's own
     * conversations, so the extra COUNT query stays cheap regardless of
     * system-wide scale, and it's one less thing to special-case
     * against Contacts' listContacts() (same reasoning, see there).
     *
     * orderByDesc('id') as a secondary sort is load-bearing, not
     * cosmetic: many conversations share the exact same
     * last_activity_at (e.g. ones that were never actually messaged
     * in, all created back-to-back). MySQL gives no ordering
     * guarantee among rows tied on the primary sort column across
     * separate LIMIT/OFFSET queries (page 1's SELECT and page 2's
     * SELECT are independent statements) - without a unique
     * tiebreaker, that instability silently duplicates some tied rows
     * across the page boundary AND drops others from every page
     * entirely, permanently invisible to the paginated conversation
     * list no matter how far the user scrolls. Confirmed by
     * reproducing it directly (57 tied-timestamp conversations lost 2
     * of them across a 20-per-page traversal until this tiebreaker
     * was added).
     */
    public function listForUser(int $userId, ?bool $archived = null, int $perPage = 20): LengthAwarePaginator
    {
        // A meeting's own room chat is a real Conversation row (see
        // MeetingService::createMeetingChat()) with only the organizer
        // as a member until someone else actually joins - if nobody
        // ever does, it's a single-member, zero-message conversation
        // that isn't a real chat and shouldn't show up here at all
        // (shows as "Unknown"/"No messages yet" otherwise, since there's
        // no second member to resolve a name/avatar from). Mirrors the
        // same exclusion AdminDashboardController's stats already apply.
        return Conversation::where('type', '!=', 'meeting')
            ->whereHas('members', function ($q) use ($userId, $archived) {
                $q->where('user_id', $userId)->whereNull('left_at');
                if ($archived !== null) {
                    $q->where('is_archived', $archived);
                }
            })
            ->with(['lastMessage', 'members.user.presence'])
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function unreadCount(int $conversationId, int $userId): int
    {
        return Message::where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $userId)
            ->whereDoesntHave('statuses', fn ($q) => $q->where('user_id', $userId)->where('status', 'read'))
            ->count();
    }

    /**
     * Total unread messages across every conversation the user belongs
     * to - backs the nav-wide badge, not any single conversation row.
     */
    public function totalUnreadCount(int $userId): int
    {
        $conversationIds = ConversationMember::where('user_id', $userId)->pluck('conversation_id');

        return Message::whereIn('conversation_id', $conversationIds)
            ->where('sender_id', '!=', $userId)
            ->whereDoesntHave('statuses', fn ($q) => $q->where('user_id', $userId)->where('status', 'read'))
            ->count();
    }

    /**
     * The "delivered" tick, decoupled from "read": fires whenever this
     * user's client syncs the conversation list at all (initial load or
     * its periodic poll), not just when a specific thread is opened -
     * this is what lets a message sit at "delivered" while the
     * recipient has the app open elsewhere but hasn't opened this
     * conversation yet, mirroring real WhatsApp's distinction between a
     * message reaching the device and it actually being read.
     */
    public function markAllDelivered(int $userId): void
    {
        MessageStatus::where('user_id', $userId)
            ->where('status', 'sent')
            ->update(['status' => 'delivered', 'delivered_at' => now()]);
    }

    public function markRead(Conversation $conversation, User $user): void
    {
        $unread = Message::where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $user->id)
            ->get();

        foreach ($unread as $message) {
            MessageStatus::updateOrCreate(
                ['message_id' => $message->id, 'user_id' => $user->id],
                ['status' => 'read', 'read_at' => now(), 'delivered_at' => now()],
            );

            if ($user->privacy?->read_receipts_enabled !== false) {
                ReadReceipt::firstOrCreate(['message_id' => $message->id, 'user_id' => $user->id], ['read_at' => now()]);
            }

            // "Immediately after being seen" (expire_on_read, see
            // MessageRepository::create()) has no fixed expires_at to
            // wait on - the read receipt IS the expiry trigger, similar
            // in spirit to a View Once media's viewed_at. Purged last,
            // after the message_status/read_receipts rows above are
            // written - both reference messages.id with cascadeOnDelete,
            // so purgeExpired()'s forceDelete() removes them along with
            // everything else (reactions, replies, pins, stars, ...)
            // that also cascades from the same FK, matching the "genuinely
            // gone, everywhere" requirement without needing an explicit
            // cleanup for each.
            if ($message->expire_on_read) {
                $this->messages->purgeExpired($message);
            }
        }
    }

    public function setTyping(int $conversationId, int $userId, string $status): void
    {
        TypingStatus::updateOrCreate(
            ['conversation_id' => $conversationId, 'user_id' => $userId],
            ['status' => $status, 'updated_at' => now()],
        );
    }

    public function clearTyping(int $conversationId, int $userId): void
    {
        TypingStatus::where('conversation_id', $conversationId)->where('user_id', $userId)->delete();
    }

    public function activeTypingUsers(int $conversationId, int $exceptUserId, int $timeoutSeconds): Collection
    {
        return TypingStatus::where('conversation_id', $conversationId)
            ->where('user_id', '!=', $exceptUserId)
            ->where('updated_at', '>=', now()->subSeconds($timeoutSeconds))
            ->with('user')
            ->get();
    }

    public function saveDraft(int $conversationId, int $userId, string $body): Draft
    {
        return Draft::updateOrCreate(['conversation_id' => $conversationId, 'user_id' => $userId], ['body' => $body]);
    }

    public function deleteDraft(int $conversationId, int $userId): void
    {
        Draft::where('conversation_id', $conversationId)->where('user_id', $userId)->delete();
    }

    public function updateSettings(ConversationMember $membership, array $data): ChatSetting
    {
        if (array_key_exists('is_archived', $data)) {
            $membership->update(['is_archived' => $data['is_archived']]);
        }

        return ChatSetting::updateOrCreate(
            ['conversation_id' => $membership->conversation_id, 'user_id' => $membership->user_id],
            array_intersect_key($data, array_flip(['muted_until', 'wallpaper_id', 'notifications_enabled'])),
        );
    }

    /**
     * Conversation-wide (unlike updateSettings() above, which is
     * per-user via chat_settings) - any member sets it once for
     * everyone, matching real WhatsApp semantics.
     */
    public function updateDisappearingMessages(Conversation $conversation, ?int $seconds): Conversation
    {
        $conversation->update(['disappearing_messages_seconds' => $seconds]);

        return $conversation;
    }
}
