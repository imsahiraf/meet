<?php

namespace Modules\Whiteboard\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Modules\Media\Models\Media;
use Modules\Meetings\Models\Meeting;
use Modules\Meetings\Models\MeetingParticipant;
use Modules\Whiteboard\Exceptions\CannotDeleteLastPageException;
use Modules\Whiteboard\Models\Whiteboard;
use Modules\Whiteboard\Models\WhiteboardExport;
use Modules\Whiteboard\Models\WhiteboardHistory;
use Modules\Whiteboard\Models\WhiteboardObject;
use Modules\Whiteboard\Models\WhiteboardPage;

class WhiteboardService
{
    /**
     * FR-141 - opens (or resumes) the one whiteboard a meeting has.
     * DDS-4 models meetings -> whiteboards as 1:N, but nothing in the
     * FRs describes a meeting ever having more than one concurrent
     * board, so "start" is idempotent: it returns the existing open
     * whiteboard rather than creating a second one.
     */
    public function open(Meeting $meeting, MeetingParticipant $host): Whiteboard
    {
        $existing = Whiteboard::where('meeting_id', $meeting->id)->where('status', 'active')->first();

        if ($existing) {
            return $existing;
        }

        $whiteboard = Whiteboard::create([
            'meeting_id' => $meeting->id,
            ...$this->creatorColumns('created_by', $host),
            'permission_mode' => $meeting->settings->whiteboard_permission ?? 'all_participants',
        ]);

        WhiteboardPage::create(['whiteboard_id' => $whiteboard->id, 'page_number' => 1]);

        return $whiteboard;
    }

    public function setPermission(Whiteboard $whiteboard, string $mode): Whiteboard
    {
        $whiteboard->forceFill(['permission_mode' => $mode])->save();

        return $whiteboard;
    }

    /**
     * FR-149 - add a new page, numbered after the current last page.
     */
    public function addPage(Whiteboard $whiteboard, ?string $name = null): WhiteboardPage
    {
        $nextNumber = ((int) $whiteboard->pages()->max('page_number')) + 1;

        return WhiteboardPage::create([
            'whiteboard_id' => $whiteboard->id,
            'page_number' => $nextNumber,
            'name' => $name,
        ]);
    }

    public function deletePage(Whiteboard $whiteboard, WhiteboardPage $page): void
    {
        if ($whiteboard->pages()->count() <= 1) {
            throw new CannotDeleteLastPageException();
        }

        $page->delete();
    }

    /**
     * FR-143/144/148 - create a drawn/placed object. Returns the
     * object plus its history entry id so the caller (an authorized
     * participant) can immediately undo their own stroke if desired.
     */
    public function addObject(WhiteboardPage $page, MeetingParticipant $participant, string $tool, array $data, ?string $color, ?int $thickness): WhiteboardObject
    {
        $maxZ = (int) WhiteboardObject::where('whiteboard_page_id', $page->id)->max('z_index');

        $object = WhiteboardObject::create([
            'whiteboard_page_id' => $page->id,
            'tool' => $tool,
            'data' => $data,
            'color' => $color,
            'thickness' => $thickness,
            'z_index' => $maxZ + 1,
            ...$this->creatorColumns('created_by', $participant),
        ]);

        WhiteboardHistory::create([
            'whiteboard_id' => $page->whiteboard_id,
            'whiteboard_object_id' => $object->id,
            'action' => 'create',
            'new_state' => $object->only(['data', 'color', 'thickness', 'z_index']),
            ...$this->creatorColumns('actor_id', $participant),
        ]);

        return $object;
    }

    public function deleteObject(WhiteboardObject $object, MeetingParticipant $participant): void
    {
        $object->forceFill(['is_deleted' => true])->save();

        WhiteboardHistory::create([
            'whiteboard_id' => $object->page->whiteboard_id,
            'whiteboard_object_id' => $object->id,
            'action' => 'delete',
            'previous_state' => $object->only(['data', 'color', 'thickness', 'z_index']),
            ...$this->creatorColumns('actor_id', $participant),
        ]);
    }

    /**
     * FR-152 Clear Whiteboard - soft-deletes every object on a page.
     */
    public function clearPage(WhiteboardPage $page, MeetingParticipant $participant): void
    {
        $page->objects()->get()->each(fn (WhiteboardObject $object) => $this->deleteObject($object, $participant));
    }

    /**
     * Guests have no `users` row, so every creator/actor column is
     * paired with a `..._participant_id` sibling (see this module's
     * guest-support migration) - exactly one of the pair is ever set
     * per row, resolved here from whichever kind of participant this
     * actually is.
     */
    private function creatorColumns(string $baseColumn, MeetingParticipant $participant): array
    {
        return $participant->isGuest()
            ? [$baseColumn => null, $baseColumn.'_participant_id' => $participant->id]
            : [$baseColumn => $participant->user_id, $baseColumn.'_participant_id' => null];
    }

    /**
     * FR-147 Undo - reverses the most recent not-yet-undone history
     * entry for this whiteboard (a global, shared undo stack - see
     * the migration's rationale comment).
     */
    public function undo(Whiteboard $whiteboard): ?WhiteboardHistory
    {
        $entry = WhiteboardHistory::where('whiteboard_id', $whiteboard->id)
            ->where('is_undone', false)
            ->latest('id')
            ->first();

        if (! $entry) {
            return null;
        }

        if ($entry->action === 'create') {
            $entry->object->forceFill(['is_deleted' => true])->save();
        } elseif ($entry->action === 'delete') {
            $entry->object->forceFill(['is_deleted' => false])->save();
        }

        $entry->forceFill(['is_undone' => true])->save();

        return $entry;
    }

    public function redo(Whiteboard $whiteboard): ?WhiteboardHistory
    {
        $entry = WhiteboardHistory::where('whiteboard_id', $whiteboard->id)
            ->where('is_undone', true)
            ->orderByDesc('id')
            ->first();

        if (! $entry) {
            return null;
        }

        if ($entry->action === 'create') {
            $entry->object->forceFill(['is_deleted' => false])->save();
        } elseif ($entry->action === 'delete') {
            $entry->object->forceFill(['is_deleted' => true])->save();
        }

        $entry->forceFill(['is_undone' => false])->save();

        return $entry;
    }

    /**
     * FR-151 Export - the browser renders the page's canvas to a PNG/
     * JPG blob client-side and uploads it through the existing Media
     * engine (see the whiteboard_exports migration comment); this
     * just records the pointer.
     */
    public function recordExport(Whiteboard $whiteboard, Media $media, string $format, MeetingParticipant $participant): WhiteboardExport
    {
        return WhiteboardExport::create([
            'whiteboard_id' => $whiteboard->id,
            'media_id' => $media->id,
            'format' => $format,
            ...$this->creatorColumns('exported_by', $participant),
        ]);
    }

    public function canDraw(Whiteboard $whiteboard, string $role): bool
    {
        return $whiteboard->canDraw(0, $role);
    }

    public function assertCanDraw(Whiteboard $whiteboard, string $role): void
    {
        if (! $this->canDraw($whiteboard, $role)) {
            throw new AuthorizationException('You do not have permission to draw on this whiteboard.');
        }
    }

    public function objectsFor(WhiteboardPage $page): Collection
    {
        return $page->objects()->with(['creator', 'creatorParticipant.guestSession'])->get();
    }

    /**
     * FR-148 "only incremental updates shall be exchanged after the
     * initial synchronization" - the polling protocol itself isn't
     * specified anywhere (see the Phase 5/6 research notes), so this
     * is designed to mirror Chat/Calls' proven cursor-based polling:
     * `created`/`restored` cover new or undo-resurrected strokes,
     * `removed` covers erase/clear/undo-of-a-create, each detected
     * from a client-held (after_id, since) pair.
     */
    public function syncPage(WhiteboardPage $page, int $afterId, ?\Illuminate\Support\Carbon $since): array
    {
        $created = WhiteboardObject::where('whiteboard_page_id', $page->id)
            ->where('id', '>', $afterId)
            ->where('is_deleted', false)
            ->with(['creator', 'creatorParticipant.guestSession'])
            ->orderBy('id')
            ->get();

        $restored = $since
            ? WhiteboardObject::where('whiteboard_page_id', $page->id)
                ->where('id', '<=', $afterId)
                ->where('is_deleted', false)
                ->where('updated_at', '>', $since)
                ->with(['creator', 'creatorParticipant.guestSession'])
                ->get()
            : collect();

        $removed = $since
            ? WhiteboardObject::where('whiteboard_page_id', $page->id)
                ->where('is_deleted', true)
                ->where('updated_at', '>', $since)
                ->pluck('id')
            : collect();

        return ['created' => $created, 'restored' => $restored, 'removed' => $removed];
    }
}
