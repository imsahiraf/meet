<?php

namespace Modules\Meetings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Repositories\MeetingRepository;
use Modules\Meetings\Services\MeetingService;

/**
 * A meeting an organizer creates but never actually joins (abandons
 * the lobby - see MeetingService::createInstant()'s docblock) or one
 * everybody has since left previously just sat "active"/"locked"
 * forever with no participant ever cleaning it up - there's no host
 * action that ever runs once nobody is left to take it. Ends any
 * active/locked meeting that's had nobody genuinely present (using
 * the SAME staleness definition roster() already polls on, not a raw
 * left_at check) for a full 5 minutes.
 *
 * Deliberately NOT ShouldQueue - same reasoning as every other cron
 * job in this app (see MarkMissedCallsJob's docblock): a fast, bounded
 * DB sweep has no reason to depend on a separate, easy-to-forget queue
 * worker that doesn't exist on this cPanel deployment anyway.
 */
class EndInactiveMeetingsJob
{
    use Dispatchable, Queueable, SerializesModels;

    private const INACTIVE_MINUTES = 5;

    public function handle(MeetingRepository $meetings): void
    {
        $threshold = now()->subMinutes(self::INACTIVE_MINUTES);

        Meeting::whereIn('status', ['active', 'locked'])
            ->where('created_at', '<', $threshold)
            ->get()
            ->each(function (Meeting $meeting) use ($meetings, $threshold) {
                $hasLiveParticipant = $meeting->activeParticipants()
                    ->where(function ($query) use ($threshold) {
                        $query->whereNull('last_seen_at')->orWhere('last_seen_at', '>=', $threshold);
                    })
                    ->exists();

                if ($hasLiveParticipant) {
                    return;
                }

                // Nobody is currently present - but only end it once
                // that's been true for the full grace window: if the
                // last person here left recently, give the meeting a
                // chance to be rejoined rather than killing it the
                // instant it empties out.
                $lastLeftAt = $meeting->participants()->whereNotNull('left_at')->max('left_at');
                if ($lastLeftAt && $lastLeftAt >= $threshold) {
                    return;
                }

                $endedAt = now();
                $meeting->activeParticipants()->update(['left_at' => $endedAt]);
                $meeting->forceFill(['status' => 'ended', 'actual_end' => $endedAt])->save();
                $meetings->log($meeting, null, 'meeting_auto_ended', ['reason' => 'inactive_for_'.self::INACTIVE_MINUTES.'_minutes']);
            });
    }
}
