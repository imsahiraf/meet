<?php

namespace Modules\Groups\Repositories;

use Illuminate\Support\Collection;
use Modules\Chat\Models\Message;
use Modules\Groups\Models\Group;

class GroupContentRepository
{
    public function media(Group $group): Collection
    {
        return Message::where('conversation_id', $group->conversation_id)
            ->whereIn('message_type', ['image', 'video', 'audio'])
            ->latest()
            ->get();
    }

    public function documents(Group $group): Collection
    {
        return Message::where('conversation_id', $group->conversation_id)
            ->where('message_type', 'file')
            ->latest()
            ->get();
    }

    /**
     * No dedicated 'link' message_type exists - links are plain-text
     * messages containing a URL, so they're detected from the body.
     */
    public function links(Group $group): Collection
    {
        return Message::where('conversation_id', $group->conversation_id)
            ->where('message_type', 'text')
            ->where('body', 'regexp', 'https?://')
            ->latest()
            ->get();
    }
}
