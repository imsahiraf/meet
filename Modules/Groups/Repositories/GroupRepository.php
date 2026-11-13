<?php

namespace Modules\Groups\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Chat\Models\Conversation;
use Modules\Chat\Models\ConversationMember;
use Modules\Groups\Models\Group;
use Modules\Groups\Models\GroupLog;
use Modules\Groups\Models\GroupMember;
use Modules\Groups\Models\GroupPermission;
use Modules\Groups\Models\GroupRole;

class GroupRepository
{
    /**
     * Default role/permission matrix - DDS-3 documents the four default
     * roles and gives permission-key examples, but not the concrete
     * per-role matrix. Derived from SRS FR-072's per-role permission
     * lists (Owner/Administrator/Member) plus a Moderator role that
     * mirrors Administrator's moderation-only subset.
     */
    private const ROLE_PERMISSIONS = [
        GroupRole::ADMIN => [
            'manage_group', 'manage_members', 'pin_messages', 'manage_join_requests',
            'manage_invites', 'manage_announcements', 'manage_polls', 'manage_events',
            'send_messages',
        ],
        GroupRole::MODERATOR => [
            'pin_messages', 'manage_join_requests', 'send_messages',
        ],
        GroupRole::MEMBER => [
            'send_messages',
        ],
    ];

    public function create(User $owner, string $name, ?string $description, ?string $avatar, array $memberIds): Group
    {
        return DB::transaction(function () use ($owner, $name, $description, $avatar, $memberIds) {
            $conversation = Conversation::create([
                'type' => 'group',
                'title' => $name,
                'created_by' => $owner->id,
                'last_activity_at' => now(),
            ]);

            $group = Group::create([
                'conversation_id' => $conversation->id,
                'owner_id' => $owner->id,
                'name' => $name,
                'description' => $description,
                'avatar' => $avatar,
            ]);

            $roles = $this->createDefaultRoles($group);

            $this->addMemberRow($group, $owner, $roles[GroupRole::OWNER]);

            foreach (array_unique($memberIds) as $memberId) {
                if ($memberId === $owner->id) {
                    continue;
                }

                $this->addMemberRow($group, User::findOrFail($memberId), $roles[GroupRole::MEMBER]);
            }

            $this->log($group, $owner->id, 'group_created', ['name' => $name]);

            return $group;
        });
    }

    /**
     * @return array<string, GroupRole>
     */
    public function createDefaultRoles(Group $group): array
    {
        $roles = [];

        foreach ([GroupRole::OWNER, GroupRole::ADMIN, GroupRole::MODERATOR, GroupRole::MEMBER] as $roleName) {
            $role = GroupRole::create([
                'group_id' => $group->id,
                'role_name' => $roleName,
                'is_system' => true,
            ]);

            foreach (GroupPermission::KEYS as $key) {
                GroupPermission::create([
                    'role_id' => $role->id,
                    'permission_key' => $key,
                    'is_allowed' => in_array($key, self::ROLE_PERMISSIONS[$roleName] ?? [], true),
                ]);
            }

            $roles[$roleName] = $role;
        }

        return $roles;
    }

    public function addMemberRow(Group $group, User $user, GroupRole $role): GroupMember
    {
        $member = GroupMember::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'joined_at' => now(),
        ]);

        ConversationMember::firstOrCreate(
            ['conversation_id' => $group->conversation_id, 'user_id' => $user->id],
            ['joined_at' => now(), 'role' => $role->role_name === GroupRole::OWNER ? 'owner' : ($role->role_name === GroupRole::ADMIN ? 'admin' : 'member')],
        );

        return $member;
    }

    public function findExistingMembership(int $groupId, int $userId): ?GroupMember
    {
        return GroupMember::where('group_id', $groupId)->where('user_id', $userId)->first();
    }

    public function activeMemberCount(int $groupId): int
    {
        return GroupMember::where('group_id', $groupId)->whereNull('left_at')->count();
    }

    public function findRole(int $groupId, string $roleName): ?GroupRole
    {
        return GroupRole::where('group_id', $groupId)->where('role_name', $roleName)->first();
    }

    public function listForUser(User $user, ?string $search = null): LengthAwarePaginator
    {
        return Group::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id)->whereNull('left_at'))
            ->where('status', '!=', 'deleted')
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->withCount(['activeMembers as member_count'])
            ->with('conversation')
            ->latest('updated_at')
            ->paginate(20);
    }

    public function log(Group $group, ?int $userId, string $event, array $metadata = []): GroupLog
    {
        return GroupLog::create([
            'group_id' => $group->id,
            'user_id' => $userId,
            'event' => $event,
            'metadata' => $metadata,
        ]);
    }

    public function generateInviteCode(): string
    {
        do {
            $code = Str::lower(Str::random(8));
        } while (Group::where('invite_code', $code)->exists());

        return $code;
    }
}
