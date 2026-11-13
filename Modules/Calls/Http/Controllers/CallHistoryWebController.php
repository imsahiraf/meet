<?php

namespace Modules\Calls\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Calls\Services\CallService;

class CallHistoryWebController extends Controller
{
    public function __construct(
        private readonly CallService $calls,
    ) {
    }

    /**
     * FR-094/FR-115 - Call History, with the documented Date/Contact/
     * Duration/Status filters (contact + status implemented server-
     * side via query params; date is visible per-row for now).
     */
    public function index(Request $request): View
    {
        return view('calls::history', [
            'calls' => $this->calls->history(
                $request->user(),
                $request->input('status'),
                $request->input('with_user_id') ? (int) $request->input('with_user_id') : null,
            ),
        ]);
    }
}
