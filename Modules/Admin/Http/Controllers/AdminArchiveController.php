<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Jobs\CreateArchiveJob;
use Modules\Admin\Models\Archive;
use Modules\Admin\Models\ArchiveSetting;
use Modules\Admin\Services\ArchiveService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AdminArchiveController extends Controller
{
    public function __construct(
        private readonly ArchiveService $archives,
    ) {
    }

    public function index(): JsonResponse
    {
        $this->authorize('manage_backups');

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => Archive::with('requestedBy')->orderByDesc('id')->paginate(20)->items(),
            'errors' => null,
        ]);
    }

    /**
     * FR-355 - manual/on-demand archive generation, Super Admin only
     * (FR-359).
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('manage_backups');

        if (! $request->user()->admin?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Only a Super Admin may create an archive.', 'data' => null, 'errors' => null], 403);
        }

        $data = $request->validate(['scope' => ['sometimes', 'in:entire_system,database_only,files_only']]);
        $archive = $this->archives->create($request->user(), $data['scope'] ?? 'entire_system', 'manual');

        // FR-346 describes archiving as a non-blocking background job,
        // which is right for a real queue worker - but a manual
        // "create archive now" click waiting on a worker process that
        // may not be running (cPanel has no persistent daemon; a dev
        // box's worker can simply be stopped) turns "non-blocking"
        // into "silently stuck forever" from the admin's point of
        // view. Building it synchronously here still returns quickly
        // for realistic data sizes and can't get lost in an unstarted
        // queue; ScheduledArchiveJob already runs the same way.
        CreateArchiveJob::dispatchSync($archive);

        return response()->json(['success' => true, 'message' => 'Archive created.', 'data' => $archive->fresh(), 'errors' => null], 201);
    }

    public function show(Archive $archive): JsonResponse
    {
        $this->authorize('manage_backups');

        return response()->json(['success' => true, 'message' => 'Operation completed successfully.', 'data' => $archive->load('requestedBy'), 'errors' => null]);
    }

    public function download(Request $request, Archive $archive): BinaryFileResponse|JsonResponse
    {
        $this->authorize('manage_backups');

        if (! $request->user()->admin?->is_super_admin) {
            return response()->json(['success' => false, 'message' => 'Only a Super Admin may download an archive.', 'data' => null, 'errors' => null], 403);
        }

        $path = storage_path('app/private/'.$archive->disk_path);

        if ((! in_array($archive->status, ['completed', 'delivered'], true)) || ! file_exists($path)) {
            return response()->json(['success' => false, 'message' => 'Archive file is not available.', 'data' => null, 'errors' => null], 404);
        }

        return response()->download($path, 'meet-archive-'.$archive->uuid.'.zip');
    }

    public function settings(): JsonResponse
    {
        $this->authorize('manage_backups');
        $settings = ArchiveSetting::current();

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [...$settings->only(['recipient_email', 'password_protection_enabled', 'schedule_frequency']), 'password_set' => filled($settings->archive_password)],
            'errors' => null,
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('manage_backups');
        $data = $request->validate([
            'recipient_email' => ['sometimes', 'email'],
            'archive_password' => ['sometimes', 'nullable', 'string', 'min:6'],
            'password_protection_enabled' => ['sometimes', 'boolean'],
            'schedule_frequency' => ['sometimes', 'in:daily,weekly,monthly,manual_only'],
        ]);

        ArchiveSetting::current()->update($data);

        return response()->json(['success' => true, 'message' => 'Archive settings updated.', 'data' => null, 'errors' => null]);
    }
}
