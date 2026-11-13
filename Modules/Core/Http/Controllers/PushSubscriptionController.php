<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\PushNotificationService;

class PushSubscriptionController extends Controller
{
    public function __construct(
        private readonly PushNotificationService $push,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => ['required', 'string']]);

        $this->push->subscribe($request->user(), $data['fcm_token'], $request->userAgent());

        return response()->json(['success' => true, 'message' => 'Subscribed.', 'data' => null, 'errors' => null]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => ['required', 'string']]);

        $this->push->unsubscribe($request->user(), $data['fcm_token']);

        return response()->json(['success' => true, 'message' => 'Unsubscribed.', 'data' => null, 'errors' => null]);
    }
}
