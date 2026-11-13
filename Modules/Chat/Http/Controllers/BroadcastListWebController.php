<?php

namespace Modules\Chat\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\BroadcastList;
use Modules\Chat\Services\BroadcastListService;
use Modules\Contacts\Services\ContactService;

class BroadcastListWebController extends Controller
{
    public function __construct(
        private readonly BroadcastListService $broadcastLists,
        private readonly ContactService $contacts,
    ) {
    }

    public function index(Request $request): View
    {
        return view('chat::broadcast-lists.index', [
            'lists' => $this->broadcastLists->listForUser($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('chat::broadcast-lists.create', [
            'contacts' => $this->contacts->listContacts($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['integer', 'exists:users,id'],
        ]);

        $list = $this->broadcastLists->create($request->user(), $data['name'], $data['recipients']);

        return redirect()->route('broadcast-lists.show', $list)->with('status', 'Broadcast list created.');
    }

    public function show(Request $request, BroadcastList $broadcastList): View
    {
        return view('chat::broadcast-lists.show', [
            'list' => $this->broadcastLists->show($request->user(), $broadcastList),
        ]);
    }

    public function destroy(Request $request, BroadcastList $broadcastList): RedirectResponse
    {
        $this->broadcastLists->delete($request->user(), $broadcastList);

        return redirect()->route('broadcast-lists.index')->with('status', 'Broadcast list deleted.');
    }

    public function send(Request $request, BroadcastList $broadcastList): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string']]);

        $sent = $this->broadcastLists->send($request->user(), $broadcastList, ['body' => $data['body']]);

        return back()->with('status', "Message sent to {$sent} recipient(s).");
    }
}
