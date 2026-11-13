<?php

namespace Modules\Admin\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Admin\Mail\TemporaryPasswordMail;
use Modules\Authentication\Models\User;
use Modules\Authentication\Repositories\UserSessionRepository;

/**
 * FR-197 User Management.
 */
class UserManagementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UserSessionRepository $sessions,
    ) {
    }

    /**
     * $accountType splits real users from the load-test-seeded ones
     * (see LOAD_TESTING.md) - identified purely by the @mail.meet email
     * domain, the same marker load-test:cleanup-users matches on.
     */
    public function list(?string $search, ?string $status, ?string $accountType = null, int $perPage = 20): LengthAwarePaginator
    {
        return User::withTrashed()
            ->with('roles')
            ->when($search, fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('full_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($accountType === 'fake', fn ($q) => $q->where('email', 'like', '%@mail.meet'))
            ->when($accountType === 'real', fn ($q) => $q->where('email', 'not like', '%@mail.meet'))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function suspend(User $target, User $actor): User
    {
        $old = $target->only('status');
        $target->forceFill(['status' => 'suspended'])->save();
        $this->forceLogout($target);
        $this->audit->log($actor, 'admin', 'user_suspended', User::class, $target->id, $old, ['status' => 'suspended']);

        return $target;
    }

    public function activate(User $target, User $actor): User
    {
        $old = $target->only('status');
        $target->forceFill(['status' => 'active'])->save();
        $this->audit->log($actor, 'admin', 'user_activated', User::class, $target->id, $old, ['status' => 'active']);

        return $target;
    }

    /** FR (One View Super Access) - see Modules/Media's migration docblock for what this grants. */
    public function grantOneViewSuperAccess(User $target, User $actor): User
    {
        $target->forceFill(['one_view_super_access' => true])->save();
        $this->audit->log($actor, 'admin', 'one_view_super_access_granted', User::class, $target->id, ['one_view_super_access' => false], ['one_view_super_access' => true]);

        return $target;
    }

    public function revokeOneViewSuperAccess(User $target, User $actor): User
    {
        $target->forceFill(['one_view_super_access' => false])->save();
        $this->audit->log($actor, 'admin', 'one_view_super_access_revoked', User::class, $target->id, ['one_view_super_access' => true], ['one_view_super_access' => false]);

        return $target;
    }

    public function delete(User $target, User $actor): void
    {
        $this->forceLogout($target);
        $target->delete();
        $this->audit->log($actor, 'admin', 'user_deleted', User::class, $target->id);
    }

    public function restore(User $target, User $actor): User
    {
        $target->restore();
        $this->audit->log($actor, 'admin', 'user_restored', User::class, $target->id);

        return $target;
    }

    /**
     * FR-197 "Force Logout" - invalidates every Sanctum token (API +
     * SPA-cookie sessions alike authenticate through Sanctum in this
     * app) and marks the session rows logged-out for the device list.
     */
    public function forceLogout(User $target): void
    {
        $target->tokens()->delete();
        $this->sessions->terminateAllForUser($target);
    }

    /**
     * FR-197 admin-triggered password reset. Always logs the target out
     * of every existing session/device first, same as any other admin
     * action that changes account access - a stale session shouldn't
     * outlive a password an admin just replaced. Only emails the new
     * password when $temporary is true, since a permanent reset is
     * something the admin is expected to relay themselves.
     */
    public function resetPassword(User $target, User $actor, string $newPassword, bool $temporary): User
    {
        $target->forceFill([
            'password' => Hash::make($newPassword),
            'must_change_password' => $temporary,
        ])->save();

        $this->forceLogout($target);
        $this->audit->log($actor, 'admin', 'user_password_reset', User::class, $target->id, null, ['is_temporary' => $temporary]);

        if ($temporary) {
            Mail::to($target->email)->send(new TemporaryPasswordMail($target->full_name, $newPassword));
        }

        return $target;
    }

    public function devices(User $target): \Illuminate\Support\Collection
    {
        return $target->sessions()->orderByDesc('last_activity_at')->get();
    }
}
