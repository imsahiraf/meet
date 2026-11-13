<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Chat\Services\ConversationService;
use Modules\Contacts\Models\ContactRequest;
use Modules\Contacts\Services\ContactService;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ContactService $contacts,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $recentConversations = $this->conversations->listForUser($user)->take(5);
        $contactsCount = $this->contacts->listContacts($user)->count();
        $pendingRequestsCount = ContactRequest::where('receiver_id', $user->id)->where('status', 'pending')->count();

        return view('core::dashboard', [
            'recentConversations' => $recentConversations,
            'contactsCount' => $contactsCount,
            'pendingRequestsCount' => $pendingRequestsCount,
        ]);
    }
}
