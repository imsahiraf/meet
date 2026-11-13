<?php

namespace Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Contacts\Exceptions\AlreadyContactsException;
use Modules\Contacts\Exceptions\ContactRequestNotPendingException;
use Modules\Contacts\Exceptions\DuplicateContactRequestException;
use Modules\Contacts\Exceptions\UserBlockedException;
use Modules\Contacts\Http\Requests\BlockUserRequest;
use Modules\Contacts\Http\Requests\SendContactRequestRequest;
use Modules\Contacts\Models\ContactRequest;
use Modules\Contacts\Services\ContactService;

class ContactsWebController extends Controller
{
    public function __construct(
        private readonly ContactService $contacts,
    ) {
    }

    public function index(Request $request): View
    {
        $contacts = $this->contacts->listContacts($request->user());

        $query = $request->string('q')->trim()->toString();
        $searchResults = $query
            ? $this->contacts->searchUsers($request->user(), $query)->map(function ($result) use ($request) {
                $result->relationship_status = $this->contacts->relationshipStatus($request->user()->id, $result->id);

                return $result;
            })
            : collect();

        return view('contacts::index', [
            'contacts' => $contacts,
            'query' => $query,
            'searchResults' => $searchResults,
        ]);
    }

    public function sendRequest(SendContactRequestRequest $request): RedirectResponse
    {
        try {
            $this->contacts->sendRequest(
                $request->user(),
                (int) $request->input('receiver_id'),
                $request->input('message'),
            );
        } catch (DuplicateContactRequestException|AlreadyContactsException|UserBlockedException $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return back()->with('status', 'Contact request sent.');
    }

    public function requests(Request $request): View
    {
        $userId = $request->user()->id;

        $pending = ContactRequest::with(['sender', 'receiver'])
            ->where('status', 'pending')
            ->where(fn ($q) => $q->where('sender_id', $userId)->orWhere('receiver_id', $userId))
            ->latest()
            ->get();

        return view('contacts::requests', [
            'incoming' => $pending->where('receiver_id', $userId),
            'outgoing' => $pending->where('sender_id', $userId),
        ]);
    }

    public function accept(Request $request, ContactRequest $contactRequest): RedirectResponse
    {
        $this->authorize('respond', $contactRequest);

        try {
            $this->contacts->acceptRequest($contactRequest);
        } catch (ContactRequestNotPendingException $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return back()->with('status', 'Contact request accepted.');
    }

    public function reject(Request $request, ContactRequest $contactRequest): RedirectResponse
    {
        $this->authorize('respond', $contactRequest);

        try {
            $this->contacts->rejectRequest($contactRequest);
        } catch (ContactRequestNotPendingException $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return back()->with('status', 'Contact request rejected.');
    }

    public function cancel(Request $request, ContactRequest $contactRequest): RedirectResponse
    {
        $this->authorize('cancel', $contactRequest);

        try {
            $this->contacts->cancelRequest($contactRequest);
        } catch (ContactRequestNotPendingException $e) {
            return back()->withErrors(['request' => $e->getMessage()]);
        }

        return back()->with('status', 'Contact request cancelled.');
    }

    public function destroy(Request $request, int $contactId): RedirectResponse
    {
        $this->contacts->removeContact($request->user(), $contactId);

        return back()->with('status', 'Contact removed.');
    }

    public function favorite(Request $request, int $contactId): RedirectResponse
    {
        $this->contacts->setFavorite($request->user(), $contactId, true);

        return back()->with('status', 'Added to favorites.');
    }

    public function unfavorite(Request $request, int $contactId): RedirectResponse
    {
        $this->contacts->setFavorite($request->user(), $contactId, false);

        return back()->with('status', 'Removed from favorites.');
    }

    public function blocked(Request $request): View
    {
        return view('contacts::blocked', ['blocked' => $this->contacts->listBlocked($request->user())]);
    }

    public function block(BlockUserRequest $request, int $userId): RedirectResponse
    {
        $this->contacts->block($request->user(), $userId, $request->input('reason'));

        return back()->with('status', 'User blocked.');
    }

    public function unblock(Request $request, int $userId): RedirectResponse
    {
        $this->contacts->unblock($request->user(), $userId);

        return back()->with('status', 'User unblocked.');
    }
}
