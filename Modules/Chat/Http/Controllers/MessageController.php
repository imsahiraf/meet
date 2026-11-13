<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Chat\Http\Requests\EditMessageRequest;
use Modules\Chat\Http\Requests\ForwardMessageRequest;
use Modules\Chat\Http\Requests\MigrateMessagesToEncryptedRequest;
use Modules\Chat\Http\Requests\ReactRequest;
use Modules\Chat\Http\Requests\ReplyMessageRequest;
use Modules\Chat\Http\Requests\SendMessageRequest;
use Modules\Chat\Http\Resources\MessageResource;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Chat\Services\MessageService;

class MessageController extends Controller
{
    public function __construct(
        private readonly MessageService $messages,
    ) {
    }

    /**
     * RAS-2 API22 - POST /messages
     */
    public function store(SendMessageRequest $request): JsonResponse
    {
        $conversation = Conversation::findOrFail($request->input('conversation_id'));
        $this->authorize('view', $conversation);

        $message = $this->messages->send($request->user(), $conversation, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Message sent.',
            'data' => new MessageResource($message->load(['sender', 'reactions', 'replyMeta', 'media', 'oneViewMedia', 'statuses'])),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-2 API23 - GET /messages/{conversationId}
     */
    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $messages = $this->messages->list(
            $request->user(),
            $conversation,
            $request->integer('before') ?: null,
            $request->integer('after') ?: null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => MessageResource::collection($messages),
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API24 - PATCH /messages/{messageId}
     */
    public function update(EditMessageRequest $request, Message $message): JsonResponse
    {
        $this->authorize('update', $message);
        $message = $this->messages->edit($request->user(), $message, $request->string('body'), $request->input('iv'));

        return response()->json([
            'success' => true,
            'message' => 'Message updated.',
            'data' => new MessageResource($message),
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API25 - DELETE /messages/{messageId}
     */
    public function destroy(Request $request, Message $message): JsonResponse
    {
        $request->validate(['mode' => ['sometimes', 'in:me,everyone']]);
        $mode = $request->input('mode', 'me');

        $this->authorize($mode === 'everyone' ? 'deleteForEveryone' : 'delete', $message);

        $this->messages->delete($request->user(), $message, $mode);

        return response()->json([
            'success' => true,
            'message' => 'Message deleted.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API26 - POST /messages/{messageId}/reaction
     */
    public function react(ReactRequest $request, Message $message): JsonResponse
    {
        $this->authorize('interact', $message);
        $this->messages->react($request->user(), $message, $request->string('emoji'));

        return response()->json([
            'success' => true,
            'message' => 'Reaction added.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API27 - DELETE /messages/{messageId}/reaction
     */
    public function removeReaction(Request $request, Message $message): JsonResponse
    {
        $this->authorize('interact', $message);
        $this->messages->removeReaction($request->user(), $message);

        return response()->json([
            'success' => true,
            'message' => 'Reaction removed.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API28 - POST /messages/{messageId}/reply
     */
    public function reply(ReplyMessageRequest $request, Message $message): JsonResponse
    {
        $this->authorize('interact', $message);
        $reply = $this->messages->reply($request->user(), $message, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Reply sent.',
            'data' => new MessageResource($reply->load(['sender', 'replyMeta', 'media', 'oneViewMedia', 'statuses'])),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-2 API29 - POST /messages/{messageId}/forward
     */
    public function forward(ForwardMessageRequest $request, Message $message): JsonResponse
    {
        $this->authorize('interact', $message);
        $destination = Conversation::findOrFail($request->input('conversation_id'));
        $this->authorize('view', $destination);

        $forwarded = $this->messages->forward($request->user(), $message, $destination, $request->only(['body', 'encryption_mode', 'iv', 'key_version']));

        return response()->json([
            'success' => true,
            'message' => 'Message forwarded.',
            'data' => new MessageResource($forwarded->load(['sender', 'forwardMeta', 'media', 'oneViewMedia', 'statuses'])),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-2 API30 - POST /messages/{messageId}/star (toggle)
     */
    public function star(Request $request, Message $message): JsonResponse
    {
        $this->authorize('interact', $message);
        $starred = $this->messages->toggleStar($request->user(), $message);

        return response()->json([
            'success' => true,
            'message' => $starred ? 'Message starred.' : 'Message unstarred.',
            'data' => ['is_starred' => $starred],
            'errors' => null,
        ]);
    }

    public function starred(Request $request): JsonResponse
    {
        $starred = $this->messages->listStarred($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $starred->map(fn ($s) => new MessageResource($s->message)),
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API31 - POST /messages/{messageId}/pin (toggle)
     */
    public function pin(Request $request, Message $message): JsonResponse
    {
        $this->authorize('interact', $message);
        $pinned = $this->messages->togglePin($request->user(), $message);

        return response()->json([
            'success' => true,
            'message' => $pinned ? 'Message pinned.' : 'Message unpinned.',
            'data' => ['is_pinned' => $pinned],
            'errors' => null,
        ]);
    }

    public function migrateToEncrypted(MigrateMessagesToEncryptedRequest $request, Conversation $conversation): JsonResponse
    {
        $updated = $this->messages->migrateToEncrypted(
            $request->user(),
            $conversation,
            $request->validated('key_version'),
            $request->validated('messages'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => ['updated' => $updated],
            'errors' => null,
        ]);
    }

    public function pinned(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $pinned = $this->messages->listPinned($request->user(), $conversation);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $pinned->map(fn ($p) => new MessageResource($p->message)),
            'errors' => null,
        ]);
    }

    /**
     * Lets the client advance an already-rendered message's ticks
     * (sent -> delivered -> read) without a full message reload.
     */
    public function statuses(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $statuses = $this->messages->myMessageStatuses($request->user(), $conversation, $request->integer('since', 0));

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $statuses,
            'errors' => null,
        ]);
    }

    /**
     * Lets the client patch reaction badges on already-rendered
     * messages live (add or remove), instead of only ever seeing a
     * reaction change on the next full loadMessages() - see
     * MessageService::reactionsInRange()'s docblock.
     */
    public function reactionUpdates(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $updates = $this->messages->reactionsInRange(
            $request->user(),
            $conversation,
            $request->integer('from', 0),
            $request->integer('to', 0),
        );

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $updates,
            'errors' => null,
        ]);
    }

    /**
     * Called by the service worker's own push handler (no page open at
     * all) purely for its delivery side effect - see
     * MessageService::markDelivered()'s docblock.
     */
    public function markDelivered(Request $request, Conversation $conversation): JsonResponse
    {
        $this->messages->markDelivered($request->user(), $conversation);

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => null, 'errors' => null]);
    }

    /**
     * Sender-only "Info" view: exact per-recipient delivered/read
     * timestamps, reachable from the message's own action menu.
     */
    public function info(Request $request, Message $message): JsonResponse
    {
        $this->authorize('view', $message->conversation);
        $statuses = $this->messages->deliveryInfo($request->user(), $message);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $statuses->map(fn ($s) => [
                'user' => [
                    'uuid' => $s->user->uuid,
                    'full_name' => $s->user->full_name,
                    'profile_photo' => $s->user->photoFor($request->user()),
                ],
                'status' => $s->status,
                'delivered_at' => $s->delivered_at?->toISOString(),
                'read_at' => $s->read_at?->toISOString(),
            ]),
            'errors' => null,
        ]);
    }
}
