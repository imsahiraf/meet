<?php

namespace Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Contacts\Http\Resources\UserSearchResource;
use Modules\Contacts\Services\ContactService;

class UserSearchController extends Controller
{
    public function __construct(
        private readonly ContactService $contacts,
    ) {
    }

    /**
     * RAS-2 API7 - GET /users/search
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2']]);

        $results = $this->contacts->searchUsers($request->user(), $request->string('q'));

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => UserSearchResource::collection($results),
            'errors' => null,
        ]);
    }
}
