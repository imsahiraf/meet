<?php

namespace Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Contacts\Http\Resources\ContactResource;
use Modules\Contacts\Services\ContactService;

class ContactController extends Controller
{
    public function __construct(
        private readonly ContactService $contacts,
    ) {
    }

    /**
     * RAS-2 API11 - GET /contacts
     */
    public function index(Request $request): JsonResponse
    {
        $contacts = $this->contacts->listContacts($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => ContactResource::collection($contacts),
            'meta' => ['current_page' => $contacts->currentPage(), 'last_page' => $contacts->lastPage(), 'total' => $contacts->total(), 'has_more_pages' => $contacts->hasMorePages()],
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API12 - DELETE /contacts/{contactId}
     *
     * {contactId} is the other user's id (contact_user_id) - clients only
     * ever know contacts by the user they represent.
     */
    public function destroy(Request $request, int $contactId): JsonResponse
    {
        $this->contacts->removeContact($request->user(), $contactId);

        return response()->json([
            'success' => true,
            'message' => 'Contact removed.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API15 - POST /contacts/{contactId}/favorite
     */
    public function favorite(Request $request, int $contactId): JsonResponse
    {
        $this->contacts->setFavorite($request->user(), $contactId, true);

        return response()->json([
            'success' => true,
            'message' => 'Contact added to favorites.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API16 - DELETE /contacts/{contactId}/favorite
     */
    public function unfavorite(Request $request, int $contactId): JsonResponse
    {
        $this->contacts->setFavorite($request->user(), $contactId, false);

        return response()->json([
            'success' => true,
            'message' => 'Contact removed from favorites.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
