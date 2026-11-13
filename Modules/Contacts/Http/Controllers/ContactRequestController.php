<?php

namespace Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Contacts\Http\Requests\SendContactRequestRequest;
use Modules\Contacts\Http\Resources\ContactRequestResource;
use Modules\Contacts\Models\ContactRequest;
use Modules\Contacts\Services\ContactService;

class ContactRequestController extends Controller
{
    public function __construct(
        private readonly ContactService $contacts,
    ) {
    }

    /**
     * RAS-2 API8 - POST /contacts/request
     */
    public function store(SendContactRequestRequest $request): JsonResponse
    {
        $contactRequest = $this->contacts->sendRequest(
            $request->user(),
            (int) $request->input('receiver_id'),
            $request->input('message'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Contact request sent.',
            'data' => new ContactRequestResource($contactRequest->load(['sender', 'receiver'])),
            'errors' => null,
        ], 201);
    }

    /**
     * Incoming/outgoing pending requests (not explicitly numbered in RAS-2,
     * but required to build any accept/reject UI on top of API8-10).
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $requests = ContactRequest::with(['sender', 'receiver'])
            ->where('status', 'pending')
            ->where(function ($q) use ($userId) {
                $q->where('sender_id', $userId)->orWhere('receiver_id', $userId);
            })
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => ContactRequestResource::collection($requests),
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API9 - POST /contacts/request/{id}/accept
     */
    public function accept(Request $request, ContactRequest $contactRequest): JsonResponse
    {
        $this->authorize('respond', $contactRequest);
        $this->contacts->acceptRequest($contactRequest);

        return response()->json([
            'success' => true,
            'message' => 'Contact request accepted.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * RAS-2 API10 - POST /contacts/request/{id}/reject
     */
    public function reject(Request $request, ContactRequest $contactRequest): JsonResponse
    {
        $this->authorize('respond', $contactRequest);
        $this->contacts->rejectRequest($contactRequest);

        return response()->json([
            'success' => true,
            'message' => 'Contact request rejected.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * Cancel a pending request (sender-only) - completes the documented
     * `cancelled` status value, which RAS-2 doesn't give its own endpoint.
     */
    public function cancel(Request $request, ContactRequest $contactRequest): JsonResponse
    {
        $this->authorize('cancel', $contactRequest);
        $this->contacts->cancelRequest($contactRequest);

        return response()->json([
            'success' => true,
            'message' => 'Contact request cancelled.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
