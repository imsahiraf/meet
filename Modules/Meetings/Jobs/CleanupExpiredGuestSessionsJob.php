<?php

namespace Modules\Meetings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingGuestSession;
use Modules\Meetings\Models\MeetingParticipant;

// FR-327 - a guest's session must actually end when it expires, even
// if they never poll their own status again to trigger the lazy
// check in MeetingService::guestSessionStatus().
// Deliberately NOT ShouldQueue: this runs directly inside
// the scheduler process (a single reliable cron running
// `schedule:run`), instead of silently depending on a
// second, easy-to-forget queue-worker cron also being
// alive - see RegistrationOtpNotification's docblock for
// the same issue found and fixed for OTP/password-reset
// mail. Every job here is a fast, bounded DB operation with
// no reason to be deferred anyway.
class CleanupExpiredGuestSessionsJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(): void
    {
        $expired = MeetingGuestSession::where('status', 'active')
            ->where('expires_at', '<', now())
            ->get();

        $affectedMeetingIds = [];

        foreach ($expired as $session) {
            $session->forceFill(['status' => 'expired'])->save();

            MeetingParticipant::where('guest_session_id', $session->id)
                ->whereNull('left_at')
                ->update(['left_at' => now()]);

            $affectedMeetingIds[] = $session->meeting_id;
        }

        // Guest-created meetings (organizer_id null - see MeetingService
        // ::guestCreateInstant()) have no authenticated organizer who
        // could ever call end(), so without this they'd sit as
        // status=active in the meetings table forever once their guest
        // sessions expire - a real accumulation risk this app doesn't
        // have for any other meeting type. Only ends a meeting once
        // every one of its guest sessions is no longer active and it
        // has zero active participants left; never touches a meeting
        // with a real organizer_id.
        foreach (array_unique($affectedMeetingIds) as $meetingId) {
            $meeting = Meeting::find($meetingId);

            if (! $meeting || $meeting->organizer_id !== null || $meeting->status === 'ended') {
                continue;
            }

            $stillHasActiveGuestSession = MeetingGuestSession::where('meeting_id', $meetingId)
                ->where('status', 'active')
                ->exists();

            $stillHasActiveParticipant = MeetingParticipant::where('meeting_id', $meetingId)
                ->whereNull('left_at')
                ->exists();

            if (! $stillHasActiveGuestSession && ! $stillHasActiveParticipant) {
                $meeting->forceFill(['status' => 'ended', 'actual_end' => now()])->save();
            }
        }
    }
}
