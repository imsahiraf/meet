<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Calls\Models\Call;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\Message;
use Modules\Media\Models\Media;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingGuestSession;
use Modules\User\Models\UserPresence;

/**
 * FR-196 Administrator Dashboard + FR-276 Analytics Dashboard.
 * Metrics are computed live from existing tables rather than a
 * persisted `analytics_events`/`user_statistics` warehouse - DDS-7
 * gives those tables only example metric lists, not literal DDL (a
 * confirmed Phase 8 research gap), and live aggregation is both
 * simpler and always accurate for this app's scale.
 */
class AdminDashboardController extends Controller
{
    public function dashboard(): JsonResponse
    {
        $this->authorize('view_analytics');

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'total_users' => User::count(),
                'active_users' => User::where('status', 'active')->count(),
                'online_users' => UserPresence::where('presence_status', 'online')->count(),
                'todays_registrations' => User::whereDate('created_at', today())->count(),
                'active_chats' => Conversation::where('type', '!=', 'meeting')->where('last_activity_at', '>=', now()->subDay())->count(),
                'active_calls' => Call::whereIn('call_status', ['ringing', 'accepted'])->count(),
                'active_meetings' => Meeting::whereIn('status', ['waiting', 'active', 'locked'])->count(),
                'guest_sessions' => MeetingGuestSession::where('status', 'active')->count(),
                'storage_used_bytes' => (int) Media::sum('size_bytes'),
                'queue_failed_jobs' => DB::table('failed_jobs')->count(),
                'warnings' => $this->healthWarnings(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * FR-196 System Health. Catches the exact class of bug found
     * during Phase 8 QA: the Media module's configured upload ceiling
     * (Modules/Media/config/config.php max_upload_mb, default 25MB)
     * silently does nothing if PHP's own upload_max_filesize/
     * post_max_size are smaller - uploads over the PHP limit fail
     * before Laravel's validation ever runs, with no obvious error
     * pointing at the real cause. Surfaced here instead of only in a
     * code comment so a misconfigured production host is caught
     * immediately instead of discovered via a support ticket.
     */
    private function healthWarnings(): array
    {
        $warnings = [];
        $configuredMaxMb = (int) config('media.max_upload_mb', 25);
        $phpUploadMaxMb = (int) (ini_parse_quantity(ini_get('upload_max_filesize')) / 1048576);
        $phpPostMaxMb = (int) (ini_parse_quantity(ini_get('post_max_size')) / 1048576);
        $phpEffectiveMaxMb = min($phpUploadMaxMb, $phpPostMaxMb);

        if ($phpEffectiveMaxMb < $configuredMaxMb) {
            $warnings[] = sprintf(
                'PHP\'s upload_max_filesize/post_max_size (%dMB) is lower than the configured file upload limit (%dMB) - uploads between these sizes will silently fail. See public/.user.ini or your host\'s PHP configuration panel.',
                $phpEffectiveMaxMb,
                $configuredMaxMb,
            );
        }

        return $warnings;
    }

    public function userAnalytics(): JsonResponse
    {
        $this->authorize('view_analytics');

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'new_registrations_last_30_days' => User::where('created_at', '>=', now()->subDays(30))->count(),
                'verified_users' => User::whereNotNull('email_verified_at')->count(),
                'by_status' => User::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            ],
            'errors' => null,
        ]);
    }

    public function chatAnalytics(): JsonResponse
    {
        $this->authorize('view_analytics');

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'total_messages' => Message::count(),
                'messages_today' => Message::whereDate('created_at', today())->count(),
                'private_conversations' => Conversation::where('type', 'private')->count(),
                'group_conversations' => Conversation::where('type', 'group')->count(),
            ],
            'errors' => null,
        ]);
    }

    public function meetingAnalytics(): JsonResponse
    {
        $this->authorize('view_analytics');

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'meetings_created' => Meeting::count(),
                'meetings_completed' => Meeting::where('status', 'ended')->count(),
                'instant_meetings' => Meeting::where('meeting_type', 'instant')->count(),
                'scheduled_meetings' => Meeting::where('meeting_type', 'scheduled')->count(),
            ],
            'errors' => null,
        ]);
    }

    public function storageAnalytics(): JsonResponse
    {
        $this->authorize('view_analytics');

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'total_files' => Media::count(),
                'total_bytes' => (int) Media::sum('size_bytes'),
                'by_type' => Media::selectRaw('media_type, count(*) as total, sum(size_bytes) as bytes')->groupBy('media_type')->get(),
            ],
            'errors' => null,
        ]);
    }

    /** FR-287: the analytics (users, chat, meetings, storage) as CSV, Excel or PDF. */
    public function export(\Illuminate\Http\Request $request)
    {
        $this->authorize('view_analytics');
        $format = (string) $request->validate(['format' => ['required', 'in:csv,xlsx,pdf']])['format'];
        $rows = [['Section', 'Number', 'Value'],
            ['Users', 'New sign-ups in the last 30 days', User::where('created_at', '>=', now()->subDays(30))->count()],
            ['Users', 'Verified users', User::whereNotNull('email_verified_at')->count()]];
        foreach (User::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status') as $status => $n) {
            $rows[] = ['Users', 'Status: ' . $status, (int) $n];
        }
        array_push($rows,
            ['Chat', 'Messages in all', Message::count()], ['Chat', 'Messages today', Message::whereDate('created_at', today())->count()],
            ['Chat', 'Private chats', Conversation::where('type', 'private')->count()], ['Chat', 'Group chats', Conversation::where('type', 'group')->count()],
            ['Meetings', 'Meetings made', Meeting::count()], ['Meetings', 'Meetings ended', Meeting::where('status', 'ended')->count()],
            ['Meetings', 'Instant meetings', Meeting::where('meeting_type', 'instant')->count()], ['Meetings', 'Scheduled meetings', Meeting::where('meeting_type', 'scheduled')->count()],
            ['Storage', 'Files', Media::count()], ['Storage', 'Size (MB)', round(((int) Media::sum('size_bytes')) / 1048576, 1)]);
        foreach (Media::selectRaw('media_type, count(*) as total, sum(size_bytes) as bytes')->groupBy('media_type')->get() as $t) {
            $rows[] = ['Storage', 'Files: ' . $t->media_type, (int) $t->total];
        }
        $name = 'meet-report-' . now()->format('Y-m-d');
        $title = 'Meet report - ' . now()->format('d M Y H:i');

        return match ($format) {
            'csv' => response(\Modules\Admin\Services\ReportExporter::csv($rows), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $name . '.csv"']),
            'xlsx' => response(\Modules\Admin\Services\ReportExporter::xlsx($rows, 'Meet report'), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="' . $name . '.xlsx"']),
            'pdf' => response(\Modules\Admin\Services\ReportExporter::pdf($rows, $title), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $name . '.pdf"']),
        };
    }
}
