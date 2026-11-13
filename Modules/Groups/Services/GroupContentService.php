<?php

namespace Modules\Groups\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Authentication\Models\User;
use Modules\Groups\Exceptions\PollClosedException;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupAnnouncement;
use Modules\Groups\Models\GroupEvent;
use Modules\Groups\Models\GroupPoll;
use Modules\Groups\Models\PollOption;
use Modules\Groups\Repositories\GroupContentRepository;
use Modules\Groups\Repositories\GroupRepository;

class GroupContentService
{
    public function __construct(
        private readonly GroupRepository $groups,
        private readonly GroupContentRepository $content,
    ) {
    }

    /**
     * RAS-3 API21 - FR-080 announcement-only groups (admin posting,
     * member read-only).
     */
    public function createAnnouncement(Group $group, User $actor, string $title, string $announcement, bool $isPinned, ?string $expiresAt): GroupAnnouncement
    {
        $record = GroupAnnouncement::create([
            'group_id' => $group->id,
            'created_by' => $actor->id,
            'title' => $title,
            'announcement' => $announcement,
            'is_pinned' => $isPinned,
            'expires_at' => $expiresAt,
        ]);

        $this->groups->log($group, $actor->id, 'announcement_published', ['announcement_id' => $record->id]);

        return $record;
    }

    public function updateAnnouncement(GroupAnnouncement $announcement, array $data): GroupAnnouncement
    {
        $announcement->fill(array_intersect_key($data, array_flip(['title', 'announcement', 'is_pinned', 'expires_at'])));
        $announcement->save();

        return $announcement;
    }

    public function deleteAnnouncement(GroupAnnouncement $announcement): void
    {
        $announcement->delete();
    }

    public function listAnnouncements(Group $group): Collection
    {
        return $group->announcements()->with('creator')->orderByDesc('is_pinned')->latest()->get();
    }

    /**
     * RAS-3 API25 - FR-082 single/multiple choice polls.
     */
    public function createPoll(Group $group, User $actor, string $question, array $options, bool $allowMultipleVotes, ?string $closesAt): GroupPoll
    {
        if (count($options) < 2) {
            throw new InvalidArgumentException('A poll needs at least two options.');
        }

        $poll = GroupPoll::create([
            'group_id' => $group->id,
            'created_by' => $actor->id,
            'question' => $question,
            'allow_multiple_votes' => $allowMultipleVotes,
            'closes_at' => $closesAt,
        ]);

        foreach (array_values($options) as $index => $optionText) {
            PollOption::create(['poll_id' => $poll->id, 'option_text' => $optionText, 'sort_order' => $index]);
        }

        $this->groups->log($group, $actor->id, 'poll_created', ['poll_id' => $poll->id]);

        return $poll->load('options');
    }

    /**
     * RAS-3 API26.
     */
    public function vote(GroupPoll $poll, User $user, array $optionIds): void
    {
        if ($poll->isClosed()) {
            throw new PollClosedException();
        }

        $validOptionIds = $poll->options()->pluck('id');
        $optionIds = array_values(array_intersect($optionIds, $validOptionIds->all()));

        if (empty($optionIds)) {
            throw new InvalidArgumentException('Select at least one valid poll option.');
        }

        if (! $poll->allow_multiple_votes) {
            $optionIds = [array_slice($optionIds, 0, 1)[0]];
        }

        $poll->votes()->where('user_id', $user->id)->delete();

        foreach ($optionIds as $optionId) {
            $poll->votes()->create(['option_id' => $optionId, 'user_id' => $user->id]);
        }
    }

    /**
     * RAS-3 API27.
     */
    public function results(GroupPoll $poll): GroupPoll
    {
        return $poll->load(['options.votes', 'creator']);
    }

    /**
     * RAS-3 API28 - manual close reuses closes_at (DDS-3 defines no
     * separate "closed" flag).
     */
    public function closePoll(GroupPoll $poll): GroupPoll
    {
        $poll->forceFill(['closes_at' => $poll->closes_at && $poll->closes_at->isPast() ? $poll->closes_at : now()])->save();

        return $poll;
    }

    /**
     * RAS-3 API29 - scheduled community events (distinct from the
     * activity-log style "group events" in group_logs).
     */
    public function createEvent(Group $group, User $actor, string $title, ?string $description, string $eventStart, ?string $eventEnd): GroupEvent
    {
        return GroupEvent::create([
            'group_id' => $group->id,
            'title' => $title,
            'description' => $description,
            'event_start' => $eventStart,
            'event_end' => $eventEnd,
            'created_by' => $actor->id,
        ]);
    }

    public function updateEvent(GroupEvent $event, array $data): GroupEvent
    {
        $event->fill(array_intersect_key($data, array_flip(['title', 'description', 'event_start', 'event_end'])));
        $event->save();

        return $event;
    }

    public function deleteEvent(GroupEvent $event): void
    {
        $event->delete();
    }

    public function listEvents(Group $group): Collection
    {
        return $group->events()->orderBy('event_start')->get();
    }

    public function media(Group $group): Collection
    {
        return $this->content->media($group);
    }

    public function documents(Group $group): Collection
    {
        return $this->content->documents($group);
    }

    public function links(Group $group): Collection
    {
        return $this->content->links($group);
    }
}
