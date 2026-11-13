<?php

namespace Modules\Calls\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Calls\Models\Call;
use Modules\Calls\Services\CallService;
use Modules\Calls\Repositories\CallRepository;
use Modules\Core\Services\PushNotificationService;

/**
 * FR-090/FR-100 - a call still 'ringing' past the timeout is missed,
 * not left dangling forever. Real cPanel cron realistically runs the
 * scheduler about once a minute, so this also self-heals stale rows
 * older than the timeout regardless of exact cron cadence.
 */
// Deliberately NOT ShouldQueue: this runs directly inside
// the scheduler process (a single reliable cron running
// `schedule:run`), instead of silently depending on a
// second, easy-to-forget queue-worker cron also being
// alive - see RegistrationOtpNotification's docblock for
// the same issue found and fixed for OTP/password-reset
// mail. Every job here is a fast, bounded DB operation with
// no reason to be deferred anyway.
class MarkMissedCallsJob
{
    use Dispatchable, Queueable, SerializesModels;

    public function handle(CallRepository $calls, CallService $callService, PushNotificationService $push): void
    {
        Call::where('call_status', 'ringing')
            ->where('started_at', '<', now()->subSeconds(CallService::ringTimeoutSeconds()))
            ->with(['caller', 'receiver.preferences'])
            ->each(function (Call $call) use ($calls, $callService, $push) {
                $call->forceFill(['call_status' => 'missed', 'ended_at' => now()])->save();
                // Nobody is in a timed-out call - without releasing the
                // participant rows, both parties would stay "present" in
                // it forever and FR-091's busy check (which now reads
                // call_participants, not caller_id/receiver_id) would
                // report them permanently unable to make another call.
                $calls->markAllParticipantsLeft($call);
                $calls->recordHistory($call, 'timeout');
                $callService->logCallInConversation($call);

                // The receiver already got the "incoming call" push
                // when it started ringing, but that's gone from their
                // notification shade the moment it's dismissed/expires
                // - a real missed call needs its own follow-up
                // notification with a way to act on it after the fact
                // (call_id/caller_id/conversation_id let the service
                // worker offer "Call back" and "Message" actions - see
                // public/service-worker.js).
                if ($call->receiver && ($call->receiver->preferences?->notify_calls ?? true)) {
                    $push->sendToUser(
                        $call->receiver,
                        $call->caller?->full_name ?? 'Someone',
                        'Missed '.$call->call_type.' call',
                        [
                            'type' => 'missed_call',
                            'call_id' => $call->id,
                            'caller_id' => $call->caller_id,
                            'call_type' => $call->call_type,
                            'conversation_id' => $call->conversation_id,
                        ],
                    );
                }
            });

        $this->endAbandonedCalls($calls, $callService);
    }

    /**
     * DB hygiene for the other way a call can dangle. A 'ringing' call
     * times out above, but an ACCEPTED one has no timeout at all - and
     * this app has no persistent worker or per-participant heartbeat, so
     * if every participant's browser simply disappears (tab closed mid-
     * call, phone dies, network drops for good), nobody ever calls
     * /end and the row stays 'accepted' with its participants marked
     * present forever.
     *
     * That was harmless when membership was a caller_id/receiver_id pair
     * nothing consulted, but it isn't now: those participant rows are
     * what FR-091's busy check reads (so both people would be
     * permanently "on a call" and unable to make another), and what the
     * incoming-call poll reads (so a ghost call could keep trying to
     * pull them back in - see CallRepository::findIncomingCallFor()'s
     * own freshness bound, which is the second half of this defence).
     *
     * The cutoff is a plain maximum call duration rather than an
     * inactivity window, because inactivity is exactly what cannot be
     * observed here: polling leaves no trace, and adding a heartbeat
     * would mean a new write on every poll from every participant.
     */
    private function endAbandonedCalls(CallRepository $calls, CallService $callService): void
    {
        $maxSeconds = (int) config('calls.max_duration_seconds', 12 * 3600);

        Call::where('call_status', 'accepted')
            ->where('answered_at', '<', now()->subSeconds($maxSeconds))
            ->each(function (Call $call) use ($calls, $callService) {
                $endedAt = now();

                $call->forceFill([
                    'call_status' => 'ended',
                    'ended_at' => $endedAt,
                    'duration_seconds' => $call->answered_at ? $call->answered_at->diffInSeconds($endedAt) : 0,
                ])->save();

                $calls->markAllParticipantsLeft($call);
                $calls->recordHistory($call, 'abandoned');
                $callService->logCallInConversation($call);
            });
    }
}
