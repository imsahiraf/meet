<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Modules\Admin\Services\AuditLogger;
use Modules\Admin\Services\EnvFileService;

/**
 * Deliberately Super Admin only (see the manage_environment permission's
 * own migration docblock) and gated behind re-entering the actor's own
 * account password before every save (the same step-up pattern already
 * used for deactivating an account/disabling 2FA/removing a passkey -
 * see AuthenticationService::deactivateAccount()) - .env can hold DB/
 * mail/API credentials, so this is treated at least as sensitively as
 * those flows.
 */
class AdminEnvironmentController extends Controller
{
    public function __construct(
        private readonly EnvFileService $env,
        private readonly AuditLogger $audit,
    ) {
    }

    public function edit(Request $request): View
    {
        $this->authorize('manage_environment');

        return view('admin::environment', [
            'envVars' => $this->env->all(),
            'rawContents' => $this->env->rawContents(),
            'missingKeys' => $this->env->missingOrBlankKeys(),
        ]);
    }

    /**
     * Only keys already shaped like a real env key name are ever
     * written - a form field can't be crafted to inject an arbitrary
     * new line/key into the file this way.
     */
    public function updateFields(Request $request): RedirectResponse
    {
        $this->authorize('manage_environment');

        $data = $request->validate([
            'password' => ['required', 'string'],
            'env' => ['required', 'array'],
        ]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            return back()->withErrors(['password' => 'The current password is incorrect.'])->withInput();
        }

        $changedKeys = [];
        foreach ($data['env'] as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            if ($this->env->get($key) === (string) $value) {
                continue;
            }
            $this->env->set($key, (string) $value);
            $changedKeys[] = $key;
        }

        // Never logs the actual values (before or after) - they can be
        // real secrets (DB passwords, API keys) that have no business
        // sitting in the audit_logs table in plaintext. Just which keys
        // changed is enough of an audit trail.
        if ($changedKeys) {
            $this->audit->log($request->user(), 'admin', 'environment_updated', null, null, null, ['changed_keys' => $changedKeys]);
        }

        Artisan::call('config:clear');

        return back()->with('status', $changedKeys ? count($changedKeys).' environment variable(s) updated.' : 'No changes to save.');
    }

    public function updateRaw(Request $request): RedirectResponse
    {
        $this->authorize('manage_environment');

        $data = $request->validate([
            'password' => ['required', 'string'],
            'raw' => ['required', 'string'],
        ]);

        if (! Hash::check($data['password'], $request->user()->password)) {
            return back()->withErrors(['password' => 'The current password is incorrect.'])->withInput();
        }

        $this->env->writeRaw($data['raw']);
        $this->audit->log($request->user(), 'admin', 'environment_raw_replaced', null, null, null, null);

        Artisan::call('config:clear');

        return back()->with('status', 'Environment file updated.');
    }
}
