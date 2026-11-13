<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Models\ActivityLog;
use Modules\Admin\Models\AuditLog;

class AdminLogController extends Controller
{
    public function audit(Request $request): JsonResponse
    {
        $this->authorize('view_audit_logs');

        $logs = AuditLog::with('user')
            ->when($request->input('module'), fn ($q, $module) => $q->where('module', $module))
            ->when($request->input('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $logs->items(),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
            'errors' => null,
        ]);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        $this->authorize('view_audit_logs');

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => $auditLog->load('user'), 'errors' => null]);
    }

    public function activity(Request $request): JsonResponse
    {
        $this->authorize('view_audit_logs');

        $logs = ActivityLog::with('user')
            ->when($request->input('event'), fn ($q, $event) => $q->where('event', $event))
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => $logs->items(),
            'meta' => ['current_page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
            'errors' => null,
        ]);
    }
}
