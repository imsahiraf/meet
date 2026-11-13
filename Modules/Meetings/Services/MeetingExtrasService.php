<?php

namespace Modules\Meetings\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingEvent;
use Modules\Meetings\Models\MeetingParticipant;
use Modules\Meetings\Models\MeetingRecording;

/**
 * Live captions, recording, breakout rooms and the meeting summary (owner 2026-11-06).
 *  - Captions: each browser turns its own speech into text and sends the final lines here; everybody's room polls them
 *    (meeting_events) and the lines are the transcript for the summary.
 *  - Recording: the host's browser records the meeting (all tiles + all voices) and uploads the file; while it runs everybody
 *    sees a "Recording" sign (event "recording").
 *  - Breakout rooms: child meetings of this one; an event tells each person which room to go to, and "return" brings them back.
 *  - Summary: from the transcript, by Claude when an AI key is set, else a plain summary (who spoke how much, first lines).
 */
class MeetingExtrasService
{
    public function __construct(
        private readonly MeetingService $meetings,
        private readonly MeetingSummarizer $summarizer,
    ) {
    }

    public function events(Meeting $meeting, int $afterId): Collection
    {
        return MeetingEvent::where('meeting_id', $meeting->id)->where('id', '>', $afterId)->orderBy('id')->limit(200)->get();
    }

    public function caption(MeetingParticipant $p, string $text, ?string $name = null): MeetingEvent
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            throw ValidationException::withMessages(['text' => 'Empty caption.']);
        }

        return MeetingEvent::create(['meeting_id' => $p->meeting_id, 'participant_id' => $p->id, 'type' => 'caption',
            'payload' => ['name' => $name ?: $this->nameOf($p), 'text' => mb_substr($text, 0, 500)]]);
    }

    public function setRecording(Meeting $meeting, User $actor, bool $on): MeetingEvent
    {
        $p = $this->hostOrCoHost($meeting, $actor);

        return MeetingEvent::create(['meeting_id' => $meeting->id, 'participant_id' => $p->id, 'type' => 'recording', 'payload' => ['on' => $on, 'by' => $actor->full_name ?: $actor->email]]);
    }

    public function isRecording(Meeting $meeting): bool
    {
        $last = MeetingEvent::where('meeting_id', $meeting->id)->where('type', 'recording')->latest('id')->first();

        return (bool) ($last->payload['on'] ?? false);
    }

    public function storeRecording(Meeting $meeting, User $actor, UploadedFile $file, int $seconds): MeetingRecording
    {
        $this->hostOrCoHost($meeting, $actor);
        $path = $file->storeAs('meeting-recordings/' . $meeting->uuid, now()->format('Ymd-His') . '-' . $actor->id . '.webm', 'local');

        return MeetingRecording::create(['meeting_id' => $meeting->id, 'user_id' => $actor->id, 'path' => $path, 'size' => (int) $file->getSize(), 'seconds' => max(0, $seconds)]);
    }

    /** Recordings: the organizer and whoever was host / co-host may see them. */
    public function canSeeRecordings(Meeting $meeting, User $user): bool
    {
        return $meeting->organizer_id === $user->id
            || MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $user->id)->whereIn('role', [MeetingParticipant::HOST, MeetingParticipant::CO_HOST])->exists();
    }

    public function recordings(Meeting $meeting): Collection
    {
        return MeetingRecording::where('meeting_id', $meeting->id)->latest('id')->get()
            ->filter(fn ($r) => Storage::disk('local')->exists($r->path))->values();
    }

    /**
     * Open breakout rooms: $rooms = [['name' => 'Room 1', 'participants' => [participant ids]], ...]. Every listed person gets
     * an event with the room to go to; people not listed stay in the main room.
     *
     * @return Collection<int, Meeting>
     */
    public function openBreakouts(Meeting $meeting, User $actor, array $rooms): Collection
    {
        $host = $this->hostOrCoHost($meeting, $actor);
        $active = MeetingParticipant::where('meeting_id', $meeting->id)->whereNull('left_at')->pluck('id')->all();
        $assign = [];
        $made = collect();
        foreach (array_slice($rooms, 0, 20) as $i => $room) {
            $child = $this->meetings->createInstant($actor, mb_substr($meeting->title . ' - ' . ($room['name'] ?? ('Room ' . ($i + 1))), 0, 190));
            $child->forceFill(['parent_meeting_id' => $meeting->id, 'breakout_name' => mb_substr((string) ($room['name'] ?? ('Room ' . ($i + 1))), 0, 60), 'guest_enabled' => false])->save();
            $made->push($child);
            foreach ((array) ($room['participants'] ?? []) as $pid) {
                if (in_array((int) $pid, $active, true) && (int) $pid !== $host->id) {
                    $assign[(int) $pid] = $child->id;
                }
            }
        }
        MeetingEvent::create(['meeting_id' => $meeting->id, 'participant_id' => $host->id, 'type' => 'breakout',
            'payload' => ['assign' => (object) $assign, 'rooms' => $made->map(fn ($m) => ['id' => $m->id, 'name' => $m->breakout_name])->all()]]);

        return $made;
    }

    /** Bring everybody back to the main room and close the breakout rooms. */
    public function closeBreakouts(Meeting $meeting, User $actor): int
    {
        $host = $this->hostOrCoHost($meeting, $actor);
        $children = Meeting::where('parent_meeting_id', $meeting->id)->where('status', 'active')->get();
        foreach ($children as $child) {
            MeetingEvent::create(['meeting_id' => $child->id, 'participant_id' => null, 'type' => 'return', 'payload' => ['meeting_id' => $meeting->id]]);
            $child->forceFill(['status' => 'ended', 'actual_end' => now()])->save();
        }
        MeetingEvent::create(['meeting_id' => $meeting->id, 'participant_id' => $host->id, 'type' => 'breakout_closed', 'payload' => ['rooms' => $children->count()]]);

        return $children->count();
    }

    /** The transcript of a meeting and its breakout rooms: "Name: line" per caption. */
    public function transcript(Meeting $meeting): string
    {
        $ids = Meeting::where('parent_meeting_id', $meeting->id)->pluck('id')->push($meeting->id);

        return MeetingEvent::whereIn('meeting_id', $ids)->where('type', 'caption')->orderBy('id')->get()
            ->map(fn ($e) => ($e->payload['name'] ?? 'Someone') . ': ' . ($e->payload['text'] ?? ''))->implode("\n");
    }

    /** Make (or remake) the summary. Returns [text, made by AI?]. */
    public function summarize(Meeting $meeting, User $actor): array
    {
        if (!$this->canSeeRecordings($meeting, $actor) && !MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $actor->id)->exists()) {
            throw new AuthorizationException('Only people of this meeting may see its summary.');
        }
        $transcript = $this->transcript($meeting);
        if ($transcript === '') {
            throw ValidationException::withMessages(['summary' => 'No captions were taken in this meeting, so there is nothing to summarise. Turn on captions (CC) during the meeting.']);
        }
        $ai = false;
        try {
            $text = $this->summarizer->summarize((string) $meeting->title, mb_substr($transcript, -400000));
            $ai = true;
        } catch (\RuntimeException $e) {
            report($e);
            $text = $this->plainSummary($meeting, $transcript) . "\n\n(" . $e->getMessage() . ' A plain summary is shown instead.)';
        }
        $meeting->forceFill(['summary' => $text, 'summary_at' => now()])->save();

        return [$text, $ai];
    }

    private function plainSummary(Meeting $meeting, string $transcript): string
    {
        $lines = collect(explode("\n", $transcript));
        $speakers = $lines->map(fn ($l) => strstr($l, ':', true) ?: 'Someone')->countBy()->sortDesc();
        $out = ['Meeting: ' . $meeting->title, 'Captions: ' . $lines->count() . ' lines', 'Who spoke: ' . $speakers->map(fn ($n, $who) => "$who ($n)")->implode(', '), '', 'First lines:'];

        return implode("\n", array_merge($out, $lines->take(12)->all()));
    }

    private function hostOrCoHost(Meeting $meeting, User $actor): MeetingParticipant
    {
        $p = MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $actor->id)->whereNull('left_at')->first();
        if (!$p || !$p->isHostOrCoHost()) {
            throw new AuthorizationException('Only the host or co-host may do this.');
        }

        return $p;
    }

    private function nameOf(MeetingParticipant $p): string
    {
        if ($p->user_id) {
            $u = User::find($p->user_id);

            return (string) ($u?->full_name ?: ($u?->email ?? 'Someone'));
        }
        $guest = \Modules\Meetings\Models\MeetingGuestSession::find($p->guest_session_id);

        return (string) ($guest->guest_name ?? 'Guest');
    }
}
