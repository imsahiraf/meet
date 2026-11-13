<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authentication\Http\Resources\UserSessionResource;
use Modules\Authentication\Repositories\UserSessionRepository;

class SessionController extends Controller
{
    public function __construct(
        private readonly UserSessionRepository $sessions,
    ) {
    }

    /**
     * RAS-2 API5 - GET /users/devices
     */
    public function index(Request $request): JsonResponse
    {
        $sessions = $request->user()->sessions()->where('status', 'active')->latest('last_activity_at')->get();

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => UserSessionResource::collection($sessions),
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API6 - DELETE /users/devices/{deviceId}
     *
     * The {deviceId} is the session's uuid; terminating it revokes the
     * Sanctum token tied to that session (device).
     */
    public function destroy(Request $request, string $deviceId): JsonResponse
    {
        $session = $request->user()->sessions()->where('uuid', $deviceId)->where('status', 'active')->firstOrFail();

        $this->sessions->terminate($session);
        $request->user()->tokens()->where('id', $session->session_id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Device session terminated.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
