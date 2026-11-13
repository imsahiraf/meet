<?php

namespace Modules\Contacts\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Authentication\Models\User;
use Modules\Contacts\Models\BlockedUser;
use Modules\Contacts\Models\Contact;
use Modules\Contacts\Models\ContactRequest;
use Modules\Contacts\Models\FavoriteContact;

class ContactRepository
{
    public function searchUsers(User $searcher, string $query, int $limit = 20): Collection
    {
        $blockedByMe = BlockedUser::where('user_id', $searcher->id)->pluck('blocked_user_id');
        $blockedMe = BlockedUser::where('blocked_user_id', $searcher->id)->pluck('user_id');

        return User::query()
            ->where('id', '!=', $searcher->id)
            ->where('status', 'active')
            ->whereNotIn('id', $blockedByMe->merge($blockedMe))
            ->where(function ($q) use ($query) {
                $q->where('full_name', 'like', "%{$query}%")
                    ->orWhere('email', $query)
                    ->orWhere('phone', $query);
            })
            ->limit($limit)
            ->get();
    }

    public function findExistingContact(int $userId, int $contactUserId): ?Contact
    {
        return Contact::where('user_id', $userId)->where('contact_user_id', $contactUserId)->first();
    }

    public function findPendingRequestBetween(int $userA, int $userB): ?ContactRequest
    {
        return ContactRequest::where('status', 'pending')
            ->where(function ($q) use ($userA, $userB) {
                $q->where(fn ($q2) => $q2->where('sender_id', $userA)->where('receiver_id', $userB))
                    ->orWhere(fn ($q2) => $q2->where('sender_id', $userB)->where('receiver_id', $userA));
            })
            ->first();
    }

    public function createRequest(int $senderId, int $receiverId, ?string $message): ContactRequest
    {
        return ContactRequest::create([
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'message' => $message,
            'status' => 'pending',
        ]);
    }

    /**
     * Accepting a request creates the mutual contact relationship
     * (a row for each direction) - DDS-2 contacts business rules.
     */
    public function acceptRequest(ContactRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $request->forceFill(['status' => 'accepted', 'responded_at' => now()])->save();

            Contact::firstOrCreate([
                'user_id' => $request->sender_id,
                'contact_user_id' => $request->receiver_id,
            ]);

            Contact::firstOrCreate([
                'user_id' => $request->receiver_id,
                'contact_user_id' => $request->sender_id,
            ]);
        });
    }

    public function rejectRequest(ContactRequest $request): void
    {
        $request->forceFill(['status' => 'rejected', 'responded_at' => now()])->save();
    }

    public function cancelRequest(ContactRequest $request): void
    {
        $request->forceFill(['status' => 'cancelled', 'responded_at' => now()])->save();
    }

    /**
     * paginate(), not simplePaginate() - this is scoped to one user's own
     * contacts (indexed by user_id), so the extra COUNT query stays cheap
     * regardless of how many users/contacts exist system-wide, and the
     * UI already shows an exact "N contacts" total worth keeping correct.
     */
    public function listContacts(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return Contact::with(['contactUser.profile', 'contactUser.presence', 'contactUser.privacy'])
            ->where('user_id', $userId)
            ->orderByDesc('is_favorite')
            ->paginate($perPage);
    }

    public function removeContact(int $userId, int $contactUserId): void
    {
        Contact::where('user_id', $userId)->where('contact_user_id', $contactUserId)->delete();
        FavoriteContact::where('user_id', $userId)->where('contact_user_id', $contactUserId)->delete();
    }

    public function setFavorite(int $userId, int $contactUserId, bool $favorite): void
    {
        Contact::where('user_id', $userId)->where('contact_user_id', $contactUserId)->update(['is_favorite' => $favorite]);

        if ($favorite) {
            $nextOrder = (FavoriteContact::where('user_id', $userId)->max('sort_order') ?? 0) + 1;

            FavoriteContact::firstOrCreate(
                ['user_id' => $userId, 'contact_user_id' => $contactUserId],
                ['sort_order' => $nextOrder],
            );
        } else {
            FavoriteContact::where('user_id', $userId)->where('contact_user_id', $contactUserId)->delete();
        }
    }

    public function isBlockedEitherWay(int $userA, int $userB): bool
    {
        return BlockedUser::where(function ($q) use ($userA, $userB) {
            $q->where(fn ($q2) => $q2->where('user_id', $userA)->where('blocked_user_id', $userB))
                ->orWhere(fn ($q2) => $q2->where('user_id', $userB)->where('blocked_user_id', $userA));
        })->exists();
    }

    public function block(int $userId, int $blockedUserId, ?string $reason): BlockedUser
    {
        $blocked = BlockedUser::firstOrCreate(
            ['user_id' => $userId, 'blocked_user_id' => $blockedUserId],
            ['reason' => $reason],
        );

        // Blocking auto-rejects any pending contact request between the two users.
        $pending = $this->findPendingRequestBetween($userId, $blockedUserId);
        $pending?->forceFill(['status' => 'rejected', 'responded_at' => now()])->save();

        return $blocked;
    }

    public function unblock(int $userId, int $blockedUserId): void
    {
        BlockedUser::where('user_id', $userId)->where('blocked_user_id', $blockedUserId)->delete();
    }

    public function listBlocked(int $userId): Collection
    {
        return BlockedUser::with('blockedUser')->where('user_id', $userId)->get();
    }
}
