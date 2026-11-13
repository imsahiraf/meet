<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class AdminWebController extends Controller
{
    public function dashboard(): View
    {
        $this->authorize('view_analytics');

        return view('admin::dashboard');
    }

    public function users(): View
    {
        $this->authorize('manage_users');

        return view('admin::users');
    }

    public function roles(): View
    {
        $this->authorize('manage_roles');

        return view('admin::roles');
    }

    public function settings(): View
    {
        $this->authorize('manage_settings');

        return view('admin::settings');
    }

    public function logs(): View
    {
        $this->authorize('view_audit_logs');

        return view('admin::logs');
    }

    public function archives(): View
    {
        $this->authorize('manage_backups');

        return view('admin::archives');
    }
}
