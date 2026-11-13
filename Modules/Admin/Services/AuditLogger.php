<?php

namespace Modules\Admin\Services;

use Illuminate\Http\Request;
use Modules\Admin\Models\ActivityLog;
use Modules\Admin\Models\AuditLog;
use Modules\Authentication\Models\User;

/**
 * FR-219/FR-236 - every administrative action must be recorded,
 * append-only, with before/after values. Kept as a thin service
 * (rather than a model observer) so controllers can call it
 * explicitly at the point an admin action succeeds, matching this
 * app's established Service-layer-does-the-work convention.
 */
class AuditLogger
{
    public function log(?User $actor, string $module, string $action, ?string $recordType = null, ?int $recordId = null, ?array $oldValues = null, ?array $newValues = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'module' => $module,
            'action' => $action,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? $request->userAgent() : null,
        ]);
    }

    public function activity(?User $actor, string $event, array $context = []): ActivityLog
    {
        $request = request();

        return ActivityLog::create([
            'user_id' => $actor?->id,
            'event' => $event,
            'context' => $context ?: null,
            'ip_address' => $request instanceof Request ? $request->ip() : null,
        ]);
    }
}
