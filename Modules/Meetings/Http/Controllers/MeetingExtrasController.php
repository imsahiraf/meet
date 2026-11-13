<?php

namespace Modules\Meetings\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingGuestSession;
use Modules\Meetings\Models\MeetingRecording;
use Modules\Meetings\Services\MeetingExtrasService;
use Modules\Meetings\Services\MeetingService;

/** Captions, recording, breakout rooms and the summary of a meeting (see MeetingExtrasService). */
class MeetingExtrasController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetings,
        private readonly MeetingExtrasService $extras,
    ) {
    }

    private function ok(mixed $data, string $message = 'Operation completed successfully.', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data, 'errors' => null], $status);
    }

    private function eventsJson(Meeting $meeting, Request $request, int $me): JsonResponse
    {
        return $this->ok(['me' => $me, 'recording' => $this->extras->isRecording($meeting),
            'events' => $this->extras->events($meeting, (int) $request->input('after', 0))->map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'from' => $e->participant_id, 'payload' => $e->payload])]);
    }

    public function events(Request $request, Meeting $meeting): JsonResponse
    {
        return $this->eventsJson($meeting, $request, $this->meetings->participantFor($meeting, $request->user())->id);
    }

    public function caption(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate(['text' => ['required', 'string', 'max:500']]);
        $p = $this->meetings->participantFor($meeting, $request->user());

        return $this->ok(['id' => $this->extras->caption($p, (string) $request->input('text'), $request->user()->full_name ?: $request->user()->email)->id], 'Caption saved.', 201);
    }

    public function recording(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate(['on' => ['required', 'boolean']]);
        $this->extras->setRecording($meeting, $request->user(), $request->boolean('on'));

        return $this->ok(['recording' => $request->boolean('on')]);
    }

    public function upload(Request $request, Meeting $meeting): JsonResponse
    {
        $max = max(1, (int) config('meetings.recording_max_mb', 300)) * 1024;
        $request->validate(['file' => ['required', 'file', 'max:' . $max, 'mimetypes:video/webm,audio/webm,video/x-matroska,application/octet-stream'], 'seconds' => ['nullable', 'integer', 'min:0']]);
        $r = $this->extras->storeRecording($meeting, $request->user(), $request->file('file'), (int) $request->input('seconds', 0));

        return $this->ok(['id' => $r->id, 'size' => $r->size], 'Recording saved with the meeting.', 201);
    }

    public function recordings(Request $request, Meeting $meeting): JsonResponse
    {
        abort_unless($this->extras->canSeeRecordings($meeting, $request->user()), 403);

        return $this->ok($this->extras->recordings($meeting)->map(fn ($r) => ['id' => $r->id, 'size' => $r->size, 'seconds' => $r->seconds, 'at' => $r->created_at?->toIso8601String(),
            'url' => url('/api/v1/meetings/' . $meeting->id . '/recordings/' . $r->id)]));
    }

    public function download(Request $request, Meeting $meeting, MeetingRecording $recording)
    {
        abort_unless($recording->meeting_id === $meeting->id && $this->extras->canSeeRecordings($meeting, $request->user()), 404);
        abort_unless(Storage::disk('local')->exists($recording->path), 404);

        return Storage::disk('local')->download($recording->path, 'meeting-' . $meeting->meeting_code . '-' . $recording->id . '.webm');
    }

    public function openBreakouts(Request $request, Meeting $meeting): JsonResponse
    {
        $request->validate(['rooms' => ['required', 'array', 'min:1', 'max:20'], 'rooms.*.name' => ['nullable', 'string', 'max:60'], 'rooms.*.participants' => ['array'], 'rooms.*.participants.*' => ['integer']]);
        $rooms = $this->extras->openBreakouts($meeting, $request->user(), $request->input('rooms'));

        return $this->ok($rooms->map(fn ($m) => ['id' => $m->id, 'name' => $m->breakout_name, 'url' => url('/meetings/' . $m->id)]), 'Breakout rooms opened.', 201);
    }

    public function closeBreakouts(Request $request, Meeting $meeting): JsonResponse
    {
        return $this->ok(['closed' => $this->extras->closeBreakouts($meeting, $request->user())], 'Everybody is called back.');
    }

    public function summary(Request $request, Meeting $meeting): JsonResponse
    {
        $p = \Modules\Meetings\Models\MeetingParticipant::where('meeting_id', $meeting->id)->where('user_id', $request->user()->id)->exists();
        abort_unless($p || $meeting->organizer_id === $request->user()->id, 403);

        return $this->ok(['summary' => $meeting->summary, 'at' => $meeting->summary_at?->toIso8601String()]);
    }

    public function makeSummary(Request $request, Meeting $meeting): JsonResponse
    {
        [$text, $ai] = $this->extras->summarize($meeting, $request->user());

        return $this->ok(['summary' => $text, 'ai' => $ai], $ai ? 'Summary ready.' : 'Plain summary ready.');
    }

    // guests: only the event stream and their own captions
    public function guestEvents(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $p = $this->meetings->participantForGuestSession($guestSession);

        return $this->eventsJson(Meeting::findOrFail($guestSession->meeting_id), $request, $p->id);
    }

    public function guestCaption(Request $request, MeetingGuestSession $guestSession): JsonResponse
    {
        $request->validate(['text' => ['required', 'string', 'max:500']]);
        $p = $this->meetings->participantForGuestSession($guestSession);

        return $this->ok(['id' => $this->extras->caption($p, (string) $request->input('text'), $guestSession->guest_name)->id], 'Caption saved.', 201);
    }
}
