<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\User\Http\Requests\UpdatePreferencesRequest;
use Modules\User\Http\Requests\UpdatePresenceRequest;
use Modules\User\Http\Requests\UpdatePrivacyRequest;
use Modules\User\Http\Requests\UpdateProfileRequest;
use Modules\User\Http\Resources\ProfileResource;
use Modules\User\Services\UserProfileService;

class ProfileController extends Controller
{
    public function __construct(
        private readonly UserProfileService $profiles,
    ) {
    }

    /**
     * RAS-2 API1 - GET /users/profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['profile', 'preferences', 'privacy', 'presence']);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => new ProfileResource($user),
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API2 - PUT /users/profile
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $profile = $this->profiles->updateProfile($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => $profile,
            'errors' => null,
        ]);
    }

    /**
     * SRS Ch2 - PATCH /users/preferences
     */
    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse
    {
        $preferences = $this->profiles->updatePreferences($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Preferences updated successfully.',
            'data' => $preferences,
            'errors' => null,
        ]);
    }

    /**
     * SRS Ch2 - PATCH /users/privacy
     */
    public function updatePrivacy(UpdatePrivacyRequest $request): JsonResponse
    {
        $privacy = $this->profiles->updatePrivacy($request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Privacy settings updated successfully.',
            'data' => $privacy,
            'errors' => null,
        ]);
    }

    /**
     * FR-032 - PATCH /users/presence (polling heartbeat)
     */
    public function updatePresence(UpdatePresenceRequest $request): JsonResponse
    {
        $presence = $this->profiles->updatePresence($request->user(), $request->string('presence_status'));

        return response()->json([
            'success' => true,
            'message' => 'Presence updated.',
            'data' => $presence,
            'errors' => null,
        ]);
    }
}
