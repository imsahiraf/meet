<?php

namespace Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Authentication\Http\Requests\ForcePasswordChangeRequest;
use Modules\Authentication\Services\AuthenticationService;

/**
 * Where EnsurePasswordIsNotTemporary sends a user whose password an
 * admin marked temporary (see
 * Modules\Admin\Services\UserManagementService::resetPassword()).
 */
class ForcePasswordChangeController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
    ) {
    }

    public function show(): View
    {
        return view('authentication::force-password-change');
    }

    public function update(ForcePasswordChangeRequest $request): RedirectResponse
    {
        $this->auth->completeForcedPasswordChange($request->user(), $request->validated('new_password'));

        return redirect()->route('chat.index')->with('status', 'Your password has been updated.');
    }
}
