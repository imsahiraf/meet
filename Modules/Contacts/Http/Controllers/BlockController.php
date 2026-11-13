<?php

namespace Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Contacts\Http\Requests\BlockUserRequest;
use Modules\Contacts\Services\ContactService;

class BlockController extends Controller
{
    public function __construct(
        private readonly ContactService $contacts,
    ) {
    }

    /**
     * RAS-2 API13 - POST /users/{userId}/block
     */
    public function store(BlockUserRequest $request, int $userId): JsonResponse
    {
        $this->contacts->block($request->user(), $userId, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'User blocked.',
            'data' => null,
            'errors' => null,
        ], 201);
    }

    /**
     * RAS-2 API14 - DELETE /users/{userId}/block
     */
    public function destroy(Request $request, int $userId): JsonResponse
    {
        $this->contacts->unblock($request->user(), $userId);

        return response()->json([
            'success' => true,
            'message' => 'User unblocked.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * List the authenticated user's blocked users (FR-029).
     */
    public function index(Request $request): JsonResponse
    {
        $blocked = $this->contacts->listBlocked($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $blocked->map(fn ($b) => [
                'uuid' => $b->blockedUser->uuid,
                'full_name' => $b->blockedUser->full_name,
                'reason' => $b->reason,
                'blocked_at' => $b->created_at->toISOString(),
            ]),
            'errors' => null,
        ]);
    }
}
