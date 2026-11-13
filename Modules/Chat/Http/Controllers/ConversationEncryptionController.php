<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Services\ConversationEncryptionService;

class ConversationEncryptionController extends Controller
{
    public function __construct(
        private readonly ConversationEncryptionService $encryption,
    ) {
    }

    /**
     * Also transparently issues/rotates a key epoch when needed - see
     * ConversationEncryptionService::status(). There's no separate
     * "issue a key" request for a client to make.
     */
    public function status(Request $request, Conversation $conversation): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $this->encryption->status($request->user(), $conversation),
            'errors' => null,
        ]);
    }

    public function keyMaterial(Request $request, Conversation $conversation, int $keyVersion): JsonResponse
    {
        $keyMaterial = $this->encryption->keyMaterialForVersion($request->user(), $conversation, $keyVersion);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $keyMaterial ? ['key_material' => $keyMaterial] : null,
            'errors' => null,
        ]);
    }
}
