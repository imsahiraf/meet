<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Services\ConversationService;
use Modules\Chat\Services\MessageService;
use Modules\Groups\Models\Group;

class ChatWebController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly MessageService $messages,
    ) {
    }

    /**
     * WhatsApp-parity "Starred Messages" view - the star action and
     * its API (GET /api/v1/messages/starred) already existed; this is
     * just the missing dedicated page for it.
     */
    public function starred(Request $request): View
    {
        return view('chat::starred', [
            'starred' => $this->messages->listStarred($request->user()),
        ]);
    }

    /**
     * Server-rendered shell; the conversation list refreshes itself via
     * JS polling against the existing JSON API (FR-053, 5-10s interval).
     */
    public function index(Request $request): View
    {
        return view('chat::index', [
            'conversations' => $this->conversations->listForUser($request->user(), false),
            'mutedConversationIds' => $this->mutedConversationIds($request),
            'pollingConfig' => config('chat.polling'),
            'archivedView' => false,
        ]);
    }

    /**
     * Phase 3 - Archived Chats, kept as a separate view (rather than a
     * toggle inline) matching the "distinct folder" UX the docs and
     * WhatsApp-parity brief describe.
     */
    public function archived(Request $request): View
    {
        return view('chat::index', [
            'conversations' => $this->conversations->listForUser($request->user(), true),
            'mutedConversationIds' => $this->mutedConversationIds($request),
            'pollingConfig' => config('chat.polling'),
            'archivedView' => true,
        ]);
    }

    private function mutedConversationIds(Request $request): array
    {
        return ChatSetting::where('user_id', $request->user()->id)
            ->where('muted_until', '>', now())
            ->pluck('conversation_id')
            ->all();
    }

    /**
     * Message thread shell; messages/typing/read-receipts are all driven
     * by JS polling against the existing JSON API (FR-053, 700-1000ms).
     */
    public function show(Request $request, Conversation $conversation): View
    {
        $conversation = $this->conversations->show($request->user(), $conversation);
        $otherUser = $conversation->isPrivate()
            ? $conversation->members->firstWhere('user_id', '!=', $request->user()->id)?->user
            : null;
        $group = $conversation->isPrivate() ? null : Group::where('conversation_id', $conversation->id)->first();
        $myMembership = $conversation->members->firstWhere('user_id', $request->user()->id);

        return view('chat::show', [
            'conversation' => $conversation,
            'otherUser' => $otherUser,
            'group' => $group,
            'memberCount' => $group?->activeMembers()->count(),
            'isMuted' => in_array($conversation->id, $this->mutedConversationIds($request), true),
            'isArchived' => (bool) $myMembership?->is_archived,
            'pollingConfig' => config('chat.polling'),
        ]);
    }

    /**
     * FR-036 - reuses/creates the private conversation, then redirects
     * into the thread. Linked from the Contacts UI's "Message" action.
     */
    public function start(Request $request, int $participantId): RedirectResponse
    {
        $conversation = $this->conversations->startPrivateConversation($request->user(), $participantId);

        return redirect()->route('chat.show', $conversation);
    }

    public function archive(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->conversations->archive($request->user(), $conversation, ! $request->boolean('unarchive'));
        $status = $request->boolean('unarchive') ? 'Chat unarchived.' : 'Chat archived.';

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $status]);
        }

        return back()->with('status', $status);
    }

    public function mute(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->conversations->updateSettings($request->user(), $conversation, [
            'muted_until' => $request->boolean('muted') ? now()->addYears(100) : null,
        ]);
        $status = $request->boolean('muted') ? 'Chat muted.' : 'Chat unmuted.';

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $status]);
        }

        return back()->with('status', $status);
    }

    /**
     * WhatsApp-parity "Delete chat" - removes the conversation from this
     * user's own list only (ConversationService::leave() sets left_at on
     * their membership); the conversation and its messages persist for
     * any other member, matching the documented retention policy.
     */
    public function leave(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->conversations->leave($request->user(), $conversation);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Chat deleted.']);
        }

        return redirect()->route('chat.index')->with('status', 'Chat deleted.');
    }
}
