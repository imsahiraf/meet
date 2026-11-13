<?php

namespace Modules\Chat\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\DeletedMessage;
use Modules\Chat\Models\ForwardedMessage;
use Modules\Chat\Models\Message;
use Modules\Chat\Models\MessageReaction;
use Modules\Chat\Models\MessageReply;
use Modules\Chat\Models\MessageStatus;
use Modules\Chat\Models\PinnedMessage;
use Modules\Chat\Models\StarredMessage;
use Modules\Media\Models\Media;

class MessageRepository
{
    public function create(Conversation $conversation, User $sender, array $data): Message
    {
        // disappearing_messages_seconds has three distinct states: null
        // (off), 0 (the "immediately after being seen" sentinel - no
        // fixed offset to compute, so expires_at stays null and
        // ConversationRepository::markRead() purges the message the
        // moment its read receipt is recorded instead), and a positive
        // integer (a real fixed-timer expiry). A plain truthy check on
        // the setting would treat 0 the same as null and silently drop
        // the "after seen" case, so this checks strict-not-null +
        // greater-than-zero instead.
        $disappearingSeconds = $conversation->disappearing_messages_seconds;

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'reply_to_message_id' => $data['reply_to_message_id'] ?? null,
            'message_type' => $data['message_type'] ?? 'text',
            'body' => $data['body'] ?? null,
            'encryption_mode' => $data['encryption_mode'] ?? 'none',
            'key_version' => $data['key_version'] ?? null,
            'iv' => $data['iv'] ?? null,
            'media_id' => $data['media_id'] ?? null,
            'status' => 'sent',
            'expires_at' => ($disappearingSeconds !== null && $disappearingSeconds > 0)
                ? now()->addSeconds($disappearingSeconds)
                : null,
            'expire_on_read' => $disappearingSeconds === 0,
        ]);

        $conversation->update(['last_message_id' => $message->id, 'last_activity_at' => now()]);

        foreach ($conversation->members()->where('user_id', '!=', $sender->id)->pluck('user_id') as $recipientId) {
            MessageStatus::create(['message_id' => $message->id, 'user_id' => $recipientId, 'status' => 'sent']);
        }

        return $message;
    }

    /**
     * Called every time a recipient's client fetches messages - the
     * closest thing to a real "reached the device" signal this
     * poll-based (no websocket/push-ack) architecture has. Only ever
     * advances 'sent' -> 'delivered', never touches a row already
     * 'read' (markRead() can set delivered_at itself if a message is
     * read before it was ever separately marked delivered).
     */
    public function markDelivered(int $conversationId, int $userId): void
    {
        MessageStatus::whereHas('message', fn ($q) => $q->where('conversation_id', $conversationId))
            ->where('user_id', $userId)
            ->where('status', 'sent')
            ->update(['status' => 'delivered', 'delivered_at' => now()]);
    }

    /**
     * Per-recipient status for every message the given user sent in
     * this conversation from `$sinceId` onward - the client polls this
     * to advance an already-rendered message's ticks (sent -> delivered
     * -> read) without needing a websocket, matching this app's
     * existing poll-only architecture (FR-053).
     *
     * @return array<int, string>
     */
    /**
     * Also reports one_view_consumed per message (null for a non-
     * one_view message) - lets the client flip an already-rendered View
     * Once bubble to "Opened" live, the same poll cycle that already
     * advances ticks, rather than needing a full page reload to notice
     * the recipient has since opened it.
     */
    public function myMessageStatuses(int $conversationId, int $userId, int $sinceId): array
    {
        return Message::where('conversation_id', $conversationId)
            ->where('sender_id', $userId)
            ->where('id', '>=', $sinceId)
            ->with(['statuses', 'oneViewMedia'])
            ->get()
            ->mapWithKeys(fn (Message $m) => [$m->id => [
                'delivery_status' => $m->aggregateDeliveryStatus(),
                'one_view_consumed' => $m->message_type === 'one_view' ? ($m->oneViewMedia?->isConsumed() ?? false) : null,
            ]])
            ->all();
    }

    /**
     * Per-recipient delivered/read detail for the sender-only "Info"
     * view - deliberately reads `message_status` (always populated
     * regardless of the recipient's read-receipt privacy setting)
     * rather than `read_receipts`, since that privacy gate only governs
     * what's shown to *other* users, not the sender's own Info screen.
     */
    public function deliveryInfo(Message $message): Collection
    {
        return $message->statuses()->with('user')->get();
    }

    public function paginateForConversation(int $conversationId, ?int $before, ?int $after, int $limit = 30): Collection
    {
        $query = Message::where('conversation_id', $conversationId)->with(['sender', 'reactions', 'replyMeta', 'media', 'oneViewMedia', 'statuses']);

        if ($before) {
            $query->where('id', '<', $before);
        }

        if ($after) {
            $query->where('id', '>', $after);
        }

        return $query->orderByDesc('id')->limit($limit)->get()->sortBy('id')->values();
    }

    public function find(int $messageId): ?Message
    {
        return Message::find($messageId);
    }

    public function edit(Message $message, string $body, ?string $iv = null): Message
    {
        $message->forceFill(['body' => $body, 'iv' => $iv ?? $message->iv, 'edited_at' => now()])->save();

        return $message;
    }

    public function deleteForMe(Message $message, int $userId): void
    {
        DeletedMessage::firstOrCreate(['message_id' => $message->id, 'user_id' => $userId], ['deleted_at' => now()]);
    }

    /**
     * "Delete for everyone" previously only hid the message - the
     * underlying uploaded file stayed on disk (and its `media` row in
     * the DB) indefinitely. It's now actually removed too, unless
     * another still-live message (a forward reuses the original's
     * media_id directly, see forward() below) genuinely still needs it.
     */
    public function deleteForEveryone(Message $message): void
    {
        $mediaId = $message->media_id;

        // A pinned/starred message being deleted for everyone must stop
        // being pinned/starred, not just have those rows silently orphaned
        // (see listStarred()/listPinned()'s whereHas() guard above, which
        // exists precisely because this used to leave them dangling).
        PinnedMessage::where('message_id', $message->id)->delete();
        StarredMessage::where('message_id', $message->id)->delete();

        $message->forceFill(['status' => 'deleted', 'body' => null])->save();
        $message->delete();

        if ($mediaId) {
            $this->deleteMediaIfOrphaned($mediaId);
        }
    }

    /**
     * The disappearing-messages cleanup job (a fixed timer expiring) and
     * read-triggered expiry (the "immediately after being seen" duration,
     * see ConversationRepository::markRead()) both funnel through here.
     * Unlike deleteForEveryone() above - an explicit user action that
     * deliberately leaves a soft-deleted "This message was deleted"
     * tombstone row behind for the UI to render - a disappearing message
     * is supposed to genuinely vanish: no row (soft-deleted rows are
     * still readable via withTrashed() and any query that doesn't
     * explicitly exclude them), and no orphaned media file either.
     */
    public function purgeExpired(Message $message): void
    {
        $mediaId = $message->media_id;

        $message->forceDelete();

        if ($mediaId) {
            $this->deleteMediaIfOrphaned($mediaId);
        }
    }

    /**
     * Message::SoftDeletes' default global scope already excludes the
     * just-deleted message itself here, so this correctly answers "does
     * anything else (a forward of it, etc.) still reference this file".
     * A forceDelete()'d row (see purgeExpired() above) is gone outright,
     * so it's excluded the same way without any extra withTrashed() care.
     */
    private function deleteMediaIfOrphaned(int $mediaId): void
    {
        if (Message::where('media_id', $mediaId)->exists()) {
            return;
        }

        $media = Media::find($mediaId);
        if (! $media) {
            return;
        }

        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }

    /**
     * Companion to myMessageStatuses() above, same "patch what's already
     * rendered" idea - but for reactions instead of delivery ticks, and
     * for every message in the range (not just the polling user's own).
     * pollNewMessages()'s own `after=lastMessageId` fetch can never
     * re-return a message once it's already been rendered, so a
     * reaction added (or removed) on an old message would otherwise
     * never reach an open conversation until a full reload - this is
     * queried separately, scoped to the currently-loaded id range, so
     * that gap doesn't need the heavier full-thread refetch to close.
     *
     * A message with no reactions right now simply has no key in the
     * returned array - the caller treats that as "clear whatever this
     * message showed before", which is what makes an un-react visible
     * live too, not just a new react.
     *
     * @return array<int, array<int, array{user_id: int, emoji: string}>>
     */
    public function reactionsInRange(int $conversationId, int $fromId, int $toId): array
    {
        return MessageReaction::query()
            ->whereHas('message', fn ($q) => $q->where('conversation_id', $conversationId))
            ->whereBetween('message_id', [$fromId, $toId])
            ->get(['message_id', 'user_id', 'emoji'])
            ->groupBy('message_id')
            ->map(fn ($group) => $group->map(fn ($r) => ['user_id' => $r->user_id, 'emoji' => $r->emoji])->values()->all())
            ->all();
    }

    public function react(Message $message, int $userId, string $emoji): MessageReaction
    {
        return MessageReaction::updateOrCreate(
            ['message_id' => $message->id, 'user_id' => $userId],
            ['emoji' => $emoji],
        );
    }

    public function removeReaction(Message $message, int $userId): void
    {
        MessageReaction::where('message_id', $message->id)->where('user_id', $userId)->delete();
    }

    public function reply(Conversation $conversation, User $sender, Message $original, array $data): Message
    {
        $reply = $this->create($conversation, $sender, array_merge($data, ['reply_to_message_id' => $original->id]));

        // For an E2E original, the server never has plaintext to build a
        // preview from - the client decrypts the original itself and
        // supplies an already-encrypted preview (its own fresh IV, since
        // an IV must never be reused with the same key), instead of a
        // plaintext substring.
        $preview = $original->encryption_mode === 'e2e'
            ? ($data['reply_preview'] ?? null)
            : ($original->body ? mb_substr($original->body, 0, 200) : null);
        $previewIv = $original->encryption_mode === 'e2e' ? ($data['reply_preview_iv'] ?? null) : null;

        MessageReply::create([
            'message_id' => $reply->id,
            'replied_to_message_id' => $original->id,
            'replied_to_sender_id' => $original->sender_id,
            'replied_to_type' => $original->message_type,
            'replied_to_preview' => $preview,
            'replied_to_preview_iv' => $previewIv,
        ]);

        return $reply;
    }

    public function forward(Conversation $destination, User $sender, Message $original, array $reEncrypted = []): Message
    {
        $forwarded = $this->create($destination, $sender, [
            'message_type' => $original->message_type,
            'body' => $reEncrypted['body'] ?? $original->body,
            'encryption_mode' => $reEncrypted['encryption_mode'] ?? 'none',
            'key_version' => $reEncrypted['key_version'] ?? null,
            'iv' => $reEncrypted['iv'] ?? null,
            'media_id' => $original->media_id,
        ]);

        ForwardedMessage::create([
            'message_id' => $forwarded->id,
            'original_message_id' => $original->id,
            'forwarded_by' => $sender->id,
        ]);

        return $forwarded;
    }

    public function star(int $userId, Message $message): void
    {
        StarredMessage::firstOrCreate(['user_id' => $userId, 'message_id' => $message->id]);
    }

    public function unstar(int $userId, Message $message): void
    {
        StarredMessage::where('user_id', $userId)->where('message_id', $message->id)->delete();
    }

    /**
     * Real bug fix: a starred/pinned row's message_id survives a
     * "delete for everyone" untouched (that's a soft delete - a
     * tombstone, `$message->delete()`, not `forceFill()->forceDelete()`
     * - the starred_messages FK's cascadeOnDelete only ever fires on a
     * genuine row deletion, so nothing removes this row when the
     * message is merely soft-deleted). But Message::SoftDeletes'
     * default query scope hides that message from every relationship
     * lookup including this one's `belongsTo`, so `$entry->message` came
     * back null and crashed the view. whereHas() excludes exactly that
     * case (and the same for a genuinely forceDelete()'d/purged row,
     * e.g. an expired disappearing message) rather than rendering a
     * star/pin pointing at nothing.
     */
    public function listStarred(int $userId): Collection
    {
        return StarredMessage::with('message.sender')->whereHas('message')->where('user_id', $userId)->latest()->get();
    }

    public function isPinned(int $conversationId, int $messageId): bool
    {
        return PinnedMessage::where('conversation_id', $conversationId)->where('message_id', $messageId)->exists();
    }

    public function pin(Conversation $conversation, Message $message, int $userId): void
    {
        PinnedMessage::firstOrCreate(
            ['conversation_id' => $conversation->id, 'message_id' => $message->id],
            ['pinned_by' => $userId, 'pinned_at' => now()],
        );
    }

    public function unpin(Conversation $conversation, Message $message): void
    {
        PinnedMessage::where('conversation_id', $conversation->id)->where('message_id', $message->id)->delete();
    }

    /** Clears every pinned message in this conversation - see togglePin()'s docblock. */
    public function unpinAll(Conversation $conversation): void
    {
        PinnedMessage::where('conversation_id', $conversation->id)->delete();
    }

    /** Same orphaned-reference guard as listStarred() - see its docblock. */
    public function listPinned(int $conversationId): Collection
    {
        return PinnedMessage::with('message.sender')->whereHas('message')->where('conversation_id', $conversationId)->get();
    }

    /**
     * Encrypts existing plaintext messages in place, one conversation
     * key epoch at a time - only ever touches rows still genuinely
     * plaintext (`encryption_mode = 'none'`), so a second client racing
     * the same catch-up (or re-running it) can't clobber a message
     * another one already migrated moments ago.
     */
    public function migrateToEncrypted(int $conversationId, int $keyVersion, array $encryptedMessages): int
    {
        $updated = 0;

        foreach ($encryptedMessages as $item) {
            $updated += Message::where('id', $item['message_id'])
                ->where('conversation_id', $conversationId)
                ->where('encryption_mode', 'none')
                ->update([
                    'body' => $item['body'],
                    'iv' => $item['iv'],
                    'key_version' => $keyVersion,
                    'encryption_mode' => 'e2e',
                ]);
        }

        return $updated;
    }
}
