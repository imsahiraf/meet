<?php

namespace Modules\Chat\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\BroadcastList;
use Modules\Chat\Models\BroadcastListRecipient;

class BroadcastListRepository
{
    public function create(User $owner, string $name, array $recipientIds): BroadcastList
    {
        return DB::transaction(function () use ($owner, $name, $recipientIds) {
            $list = BroadcastList::create(['owner_id' => $owner->id, 'name' => $name]);

            foreach (array_unique($recipientIds) as $recipientId) {
                if ((int) $recipientId === $owner->id) {
                    continue;
                }

                BroadcastListRecipient::create(['broadcast_list_id' => $list->id, 'user_id' => $recipientId]);
            }

            return $list;
        });
    }

    public function update(BroadcastList $list, string $name, array $recipientIds): BroadcastList
    {
        DB::transaction(function () use ($list, $name, $recipientIds) {
            $list->update(['name' => $name]);
            $list->recipients()->delete();

            foreach (array_unique($recipientIds) as $recipientId) {
                if ((int) $recipientId === $list->owner_id) {
                    continue;
                }

                BroadcastListRecipient::create(['broadcast_list_id' => $list->id, 'user_id' => $recipientId]);
            }
        });

        return $list->fresh();
    }

    public function listForOwner(int $ownerId): Collection
    {
        return BroadcastList::where('owner_id', $ownerId)
            ->withCount('recipients')
            ->orderByDesc('created_at')
            ->get();
    }

    public function delete(BroadcastList $list): void
    {
        $list->delete();
    }
}
