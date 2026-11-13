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

    /** The audit log as a CSV for auditors (owner 2026-11-13): filters as above plus dates, up to 100,000 rows a file. */
    public function auditCsv(Request $request)
    {
        $this->authorize('view_audit_logs');
        $q = AuditLog::with('user')
            ->when($request->input('module'), fn ($q, $module) => $q->where('module', $module))
            ->when($request->input('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', $d.' 00:00:00'))
            ->when($request->input('to'), fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->orderByDesc('id')->limit(100000);

        return response()->streamDownload(function () use ($q) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date and time', 'Person', 'E-mail', 'Module', 'Action', 'Record', 'Old values', 'New values', 'IP address']);
            $safe = fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v;
            foreach ($q->lazy(1000) as $l) {
                fputcsv($out, array_map($safe, [(string) $l->created_at, $l->user?->name, $l->user?->email, $l->module, $l->action,
                    trim(class_basename((string) $l->record_type).' '.$l->record_id), is_string($l->old_values) ? $l->old_values : json_encode($l->old_values),
                    is_string($l->new_values) ? $l->new_values : json_encode($l->new_values), $l->ip_address]));
            }
            fclose($out);
        }, 'meet-audit-log-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
