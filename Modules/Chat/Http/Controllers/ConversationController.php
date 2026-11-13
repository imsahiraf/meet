<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Chat\Http\Requests\ChatSettingsRequest;
use Modules\Chat\Http\Requests\DisappearingMessagesRequest;
use Modules\Chat\Http\Requests\DraftRequest;
use Modules\Chat\Http\Requests\StartConversationRequest;
use Modules\Chat\Http\Requests\TypingRequest;
use Modules\Chat\Http\Resources\ConversationResource;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Models\ChatWallpaper;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Services\ConversationService;

class ConversationController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
    ) {
    }

    /**
     * RAS-2 API17 - POST /conversations
     */
    public function store(StartConversationRequest $request): JsonResponse
    {
        $conversation = $this->conversations->startPrivateConversation(
            $request->user(),
            (int) $request->input('participant_id'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Conversation ready.',
            'data' => new ConversationResource($conversation->load(['members.user.presence', 'lastMessage'])),
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-2 API18 - GET /conversations
     */
    public function index(Request $request): JsonResponse
    {
        $archived = $request->has('archived') ? $request->boolean('archived') : null;
        $conversations = $this->conversations->listForUser($request->user(), $archived);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => ConversationResource::collection($conversations),
            'meta' => ['current_page' => $conversations->currentPage(), 'last_page' => $conversations->lastPage(), 'total' => $conversations->total(), 'has_more_pages' => $conversations->hasMorePages()],
            'errors' => null,
        ]);
    }

    /**
     * Total unread messages across every conversation - backs the
     * nav-wide badge (not a per-conversation count).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => ['total' => $this->conversations->totalUnreadCount($request->user())],
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API19 - GET /conversations/{conversationId}
     */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $conversation = $this->conversations->show($request->user(), $conversation);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => new ConversationResource($conversation),
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API20 - POST /conversations/{conversationId}/archive
     */
    public function archive(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);
        $this->conversations->archive($request->user(), $conversation, ! $request->boolean('unarchive'));

        return response()->json([
            'success' => true,
            'message' => 'Conversation archive state updated.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API21 - DELETE /conversations/{conversationId}
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('delete', $conversation);
        $this->conversations->leave($request->user(), $conversation);

        return response()->json([
            'success' => true,
            'message' => 'Conversation removed.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API32 - POST /conversations/{conversationId}/read
     */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);
        $this->conversations->markRead($request->user(), $conversation);

        return response()->json([
            'success' => true,
            'message' => 'Conversation marked as read.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API33 - POST /conversations/{conversationId}/typing
     */
    public function typing(TypingRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);
        $this->conversations->setTyping(
            $request->user(),
            $conversation,
            $request->boolean('is_typing'),
            $request->input('status', 'typing'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Typing status updated.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API34 - POST /conversations/{conversationId}/draft
     */
    public function saveDraft(DraftRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);
        $this->conversations->saveDraft($request->user(), $conversation, $request->string('body'));

        return response()->json([
            'success' => true,
            'message' => 'Draft saved.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API35 - DELETE /conversations/{conversationId}/draft
     */
    public function deleteDraft(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);
        $this->conversations->deleteDraft($request->user(), $conversation);

        return response()->json([
            'success' => true,
            'message' => 'Draft deleted.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API36 - PATCH /conversations/{conversationId}/settings
     */
    public function updateSettings(ChatSettingsRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        $data = $request->validated();

        if (array_key_exists('mute_duration', $data)) {
            $data['muted_until'] = match ($data['mute_duration']) {
                '8_hours' => now()->addHours(8),
                '1_week' => now()->addWeek(),
                'always' => now()->addYears(100),
                default => null,
            };
            unset($data['mute_duration']);
        }

        $settings = $this->conversations->updateSettings($request->user(), $conversation, $data);

        return response()->json([
            'success' => true,
            'message' => 'Chat settings updated.',
            'data' => $settings,
            'errors' => null,
        ]);
    }

    /**
     * Disappearing messages - conversation-wide, PATCH
     * conversations/{conversation}/disappearing-messages.
     */
    public function updateDisappearingMessages(DisappearingMessagesRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $conversation);

        // 0 is a dedicated sentinel, not "off" - it means "immediately
        // after being seen" (no fixed offset; see MessageRepository::
        // create()'s strict null/positive check and ConversationRepository
        // ::markRead()'s read-triggered purge). Only an actual 'off'
        // duration (or the default branch) should map to null.
        $seconds = match ($request->validated('duration')) {
            '24_hours' => 86400,
            '7_days' => 604800,
            '90_days' => 7776000,
            'after_seen' => 0,
            default => null,
        };

        $conversation = $this->conversations->updateDisappearingMessages($request->user(), $conversation, $seconds);

        return response()->json([
            'success' => true,
            'message' => 'Disappearing messages updated.',
            'data' => ['disappearing_messages_seconds' => $conversation->disappearing_messages_seconds],
            'errors' => null,
        ]);
    }

    /**
     * WhatsApp-parity wallpaper picker - the chat_settings.wallpaper_id
     * column/relation already existed with nothing populating a catalog
     * or exposing it; this backs that picker (set via the existing
     * updateSettings() above, which already accepts wallpaper_id).
     */
    public function wallpapers(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $available = ChatWallpaper::where('is_system_default', true)
            ->orWhere('user_id', $request->user()->id)
            ->get(['id', 'name', 'type', 'value']);

        $setting = ChatSetting::where('conversation_id', $conversation->id)
            ->where('user_id', $request->user()->id)
            ->first();

        // No explicit chat_settings row yet means this conversation was
        // never customized - fall back to the user's Settings-page
        // default wallpaper rather than showing "no wallpaper". Once a
        // row exists (even set back to null explicitly), that choice
        // sticks and the fallback no longer applies.
        $current = $setting ? $setting->wallpaper_id : $request->user()->preferences?->default_wallpaper_id;

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => ['available' => $available, 'current_wallpaper_id' => $current],
            'errors' => null,
        ]);
    }
}
