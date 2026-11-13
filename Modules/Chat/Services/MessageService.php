<?php

namespace Modules\Chat\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\Chat\Exceptions\MessageActionWindowExpiredException;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Chat\Repositories\MessageRepository;
use Modules\Admin\Services\SettingsService;
use Modules\Core\Services\PushNotificationService;
use Modules\Media\Services\OneViewService;

class MessageService
{
    private const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
    private const PUSH_PREVIEW_TYPES = [
        'image' => 'Photo', 'video' => 'Video', 'audio' => 'Voice message',
        'file' => 'Document', 'location' => 'Location', 'contact' => 'Contact', 'one_view' => 'Photo/video',
    ];

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly ConversationService $conversations,
        private readonly OneViewService $oneView,
        private readonly PushNotificationService $push,
        private readonly SettingsService $settings,
        private readonly ConversationEncryptionService $encryption,
    ) {
    }

    /**
     * FR-037 / RAS-2 API22.
     */
    public function send(User $sender, Conversation $conversation, array $data): Message
    {
        $this->conversations->assertMember($sender, $conversation);
        $this->assertOwnMedia($sender, $data);

        if (($data['message_type'] ?? 'text') === 'text' && trim((string) ($data['body'] ?? '')) === '') {
            throw ValidationException::withMessages(['body' => ['Empty messages are not allowed.']]);
        }

        $message = $this->messages->create($conversation, $sender, $data);

        if (($data['message_type'] ?? null) === 'one_view') {
            $this->oneView->createFor($message);
        }

        $this->pushToRecipients($message, $conversation, $sender);

        return $message;
    }

    /**
     * Real OS-level push, on top of the foreground-only sound/browser
     * notification already wired up in the shared layout - reaches a
     * recipient even with the tab/app fully closed. Gated on the same
     * "Messages" Settings toggle as the foreground notification.
     */
    private function pushToRecipients(Message $message, Conversation $conversation, User $sender): void
    {
        $body = match (true) {
            // This app deliberately does NOT use a zero-knowledge E2E
            // scheme (see ConversationEncryptionService's own docblock)
            // - the server generates and holds every conversation's key
            // material itself, so it genuinely CAN decrypt this the same
            // way any current member's browser would, for the sole
            // purpose of a real preview instead of a bare "New message".
            // Falls back to that same generic text if decryption fails
            // for any reason (key rotated away, corrupt payload, etc.)
            // rather than surfacing an error.
            $message->encryption_mode === 'e2e' && $message->iv && $message->key_version
                => $this->encryption->decryptForPreview($conversation, $sender, $message->key_version, (string) $message->body, $message->iv) ?? 'New message',
            $message->message_type === 'text' => (string) $message->body,
            default => self::PUSH_PREVIEW_TYPES[$message->message_type] ?? 'New message',
        };

        $recipients = $conversation->members()->where('user_id', '!=', $sender->id)->with('user.preferences')->get();

        foreach ($recipients as $member) {
            if ($member->user?->preferences?->notify_messages ?? true) {
                $this->push->sendToUser($member->user, $sender->full_name, $body, ['type' => 'message', 'conversation_id' => $conversation->id]);
            }
        }
    }

    /**
     * RAS-2 API23 - supports pagination via before/after message id.
     */
    public function list(User $user, Conversation $conversation, ?int $before, ?int $after): Collection
    {
        $this->conversations->assertMember($user, $conversation);
        $this->messages->markDelivered($conversation->id, $user->id);

        return $this->messages->paginateForConversation($conversation->id, $before, $after)
            ->reject(fn (Message $m) => $m->isDeletedFor($user->id))
            ->values();
    }

    /**
     * A push notification reaching the device while the app is fully
     * backgrounded/closed is itself real evidence the message got
     * there - previously "delivered" only ever advanced from the
     * recipient's OWN client polling (list() above/markAllDelivered()),
     * so a message pushed to a closed app just sat on "sent" (single
     * tick) forever, even though it had genuinely arrived. Called by
     * the service worker's own push handler (see public/service-
     * worker.js) with no page open at all - deliberately just the
     * delivery side effect, not a full message fetch.
     */
    public function markDelivered(User $user, Conversation $conversation): void
    {
        $this->conversations->assertMember($user, $conversation);
        $this->messages->markDelivered($conversation->id, $user->id);
    }

    /**
     * Backs the client's tick-advancing poll (sent -> delivered -> read
     * on messages already rendered) - see MessageRepository::myMessageStatuses().
     *
     * @return array<int, string>
     */
    public function myMessageStatuses(User $user, Conversation $conversation, int $sinceId): array
    {
        $this->conversations->assertMember($user, $conversation);

        return $this->messages->myMessageStatuses($conversation->id, $user->id, $sinceId);
    }

    /**
     * See MessageRepository::reactionsInRange()'s docblock for why this
     * exists separately from the main after=lastMessageId message poll.
     */
    public function reactionsInRange(User $user, Conversation $conversation, int $fromId, int $toId): array
    {
        $this->conversations->assertMember($user, $conversation);

        return $this->messages->reactionsInRange($conversation->id, $fromId, $toId);
    }

    /**
     * Sender-only "Info" view: exact delivered/read timestamps per
     * recipient.
     */
    public function deliveryInfo(User $user, Message $message): Collection
    {
        $this->assertSender($user, $message);

        return $this->messages->deliveryInfo($message);
    }

    /**
     * FR-041 / RAS-2 API24.
     */
    public function edit(User $user, Message $message, string $body, ?string $iv = null): Message
    {
        $this->assertSender($user, $message);

        if ($message->created_at->diffInMinutes(now()) > config('chat.edit_window_minutes')) {
            throw new MessageActionWindowExpiredException('The edit window for this message has expired.');
        }

        if ($message->encryption_mode === 'e2e' && ! $iv) {
            throw ValidationException::withMessages(['iv' => ['A new IV is required when editing an end-to-end encrypted message.']]);
        }

        return $this->messages->edit($message, $body, $iv);
    }

    /**
     * FR-042 / RAS-2 API25.
     */
    public function delete(User $user, Message $message, string $mode): void
    {
        $this->conversations->assertMember($user, $message->conversation);

        if ($mode === 'everyone') {
            $this->assertSender($user, $message);

            $windowMinutes = $this->settings->get('delete_for_everyone_window_minutes', config('chat.delete_for_everyone_window_minutes'));

            if ($message->created_at->diffInMinutes(now()) > $windowMinutes) {
                throw new MessageActionWindowExpiredException('The delete-for-everyone window for this message has expired.');
            }

            $this->messages->deleteForEveryone($message);

            return;
        }

        $this->messages->deleteForMe($message, $user->id);
    }

    /**
     * FR-045 / RAS-2 API26.
     */
    public function react(User $user, Message $message, string $emoji): void
    {
        $this->conversations->assertMember($user, $message->conversation);

        if (! in_array($emoji, self::REACTIONS, true)) {
            throw ValidationException::withMessages(['emoji' => ['Unsupported reaction.']]);
        }

        $this->messages->react($message, $user->id, $emoji);
    }

    /**
     * RAS-2 API27.
     */
    public function removeReaction(User $user, Message $message): void
    {
        $this->conversations->assertMember($user, $message->conversation);
        $this->messages->removeReaction($message, $user->id);
    }

    /**
     * FR-043 / RAS-2 API28.
     */
    public function reply(User $user, Message $original, array $data): Message
    {
        $this->conversations->assertMember($user, $original->conversation);
        $this->assertOwnMedia($user, $data);

        return $this->messages->reply($original->conversation, $user, $original, $data);
    }

    /**
     * Security (own test 2026-11-06): a new message may only carry a file its sender uploaded. Before, any media id could be
     * put in a message, which then opened that file (anybody's photo or document, ids count up) to the conversation members.
     * Forwarding keeps working: it copies the file of a message the person may already see.
     */
    private function assertOwnMedia(User $user, array $data): void
    {
        if (!empty($data['media_id']) && !\Modules\Media\Models\Media::whereKey((int) $data['media_id'])->where('uploader_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['media_id' => ['This file was not uploaded by you.']]);
        }
    }

    /**
     * FR-044 / RAS-2 API29.
     */
    public function forward(User $user, Message $original, Conversation $destination, array $reEncrypted = []): Message
    {
        $this->conversations->assertMember($user, $destination);

        if ($original->encryption_mode === 'e2e' && ($reEncrypted['encryption_mode'] ?? null) !== 'e2e' && empty($reEncrypted['body'])) {
            // The server can't just copy this message's ciphertext into
            // another conversation - it's encrypted under a different
            // key. The client must decrypt it locally and either
            // re-encrypt for the destination's own key, or send it as
            // plaintext if the destination isn't E2E-enabled.
            throw ValidationException::withMessages([
                'body' => ['This message is end-to-end encrypted and must be decrypted and re-sent by the client to forward it.'],
            ]);
        }

        return $this->messages->forward($destination, $user, $original, $reEncrypted);
    }

    /**
     * FR-047 / RAS-2 API30.
     */
    public function toggleStar(User $user, Message $message): bool
    {
        $this->conversations->assertMember($user, $message->conversation);

        $alreadyStarred = $message->starredBy($user->id);

        $alreadyStarred ? $this->messages->unstar($user->id, $message) : $this->messages->star($user->id, $message);

        return ! $alreadyStarred;
    }

    public function listStarred(User $user): Collection
    {
        return $this->messages->listStarred($user->id);
    }

    /**
     * FR-046 / RAS-2 API31 - WhatsApp-style: only one message can be
     * pinned per chat at a time. Pinning a new one silently replaces
     * whichever was pinned before, rather than erroring once some cap is
     * reached.
     */
    public function togglePin(User $user, Message $message): bool
    {
        $conversation = $message->conversation;
        $this->conversations->assertMember($user, $conversation);

        $alreadyPinned = $this->messages->isPinned($conversation->id, $message->id);

        if ($alreadyPinned) {
            $this->messages->unpin($conversation, $message);

            return false;
        }

        $this->messages->unpinAll($conversation);
        $this->messages->pin($conversation, $message, $user->id);

        return true;
    }

    public function listPinned(User $user, Conversation $conversation): Collection
    {
        $this->conversations->assertMember($user, $conversation);

        return $this->messages->listPinned($conversation->id);
    }

    /**
     * The client-driven historical-message catch-up: once every current
     * member has an encryption key registered, whichever of them next
     * opens this conversation encrypts its existing plaintext history
     * in place, batch by batch (see MigrateMessagesToEncryptedRequest's
     * 500-per-call cap - a conversation with more untouched history
     * than that catches up over several opens, not all at once).
     */
    public function migrateToEncrypted(User $user, Conversation $conversation, int $keyVersion, array $encryptedMessages): int
    {
        $this->conversations->assertMember($user, $conversation);

        return $this->messages->migrateToEncrypted($conversation->id, $keyVersion, $encryptedMessages);
    }

    private function assertSender(User $user, Message $message): void
    {
        if ($message->sender_id !== $user->id) {
            throw new AuthorizationException('Only the sender may perform this action.');
        }
    }
}
