<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Requests\AdminResetPasswordRequest;
use Modules\Admin\Http\Resources\AdminUserResource;
use Modules\Admin\Services\UserManagementService;
use Modules\Authentication\Models\User;

class AdminUserController extends Controller
{
    public function __construct(
        private readonly UserManagementService $users,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('manage_users');

        $users = $this->users->list($request->input('search'), $request->input('status'), $request->input('account_type'));

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => AdminUserResource::collection($users),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
            'errors' => null,
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('manage_users');

        return response()->json([
            'success' => true,
            'message' => 'Operation completed successfully.',
            'data' => [
                'user' => new AdminUserResource($user->load('roles')),
                'devices' => $this->users->devices($user),
            ],
            'errors' => null,
        ]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage_users');
        $this->users->suspend($user, $request->user());

        return response()->json(['success' => true, 'message' => 'User suspended.', 'data' => new AdminUserResource($user), 'errors' => null]);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage_users');
        $this->users->activate($user, $request->user());

        return response()->json(['success' => true, 'message' => 'User activated.', 'data' => new AdminUserResource($user), 'errors' => null]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage_users');
        $this->users->delete($user, $request->user());

        return response()->json(['success' => true, 'message' => 'User deleted.', 'data' => null, 'errors' => null]);
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $this->authorize('manage_users');
        $user = User::withTrashed()->findOrFail($id);
        $this->users->restore($user, $request->user());

        return response()->json(['success' => true, 'message' => 'User restored.', 'data' => new AdminUserResource($user), 'errors' => null]);
    }

    public function forceLogout(User $user): JsonResponse
    {
        $this->authorize('manage_users');
        $this->users->forceLogout($user);

        return response()->json(['success' => true, 'message' => 'User logged out of all devices.', 'data' => null, 'errors' => null]);
    }

    public function resetPassword(AdminResetPasswordRequest $request, User $user): JsonResponse
    {
        $this->authorize('manage_users');
        $this->users->resetPassword($user, $request->user(), $request->validated('new_password'), $request->boolean('is_temporary'));

        return response()->json(['success' => true, 'message' => 'Password reset.', 'data' => new AdminUserResource($user), 'errors' => null]);
    }

    public function grantOneViewSuperAccess(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage_users');
        $this->users->grantOneViewSuperAccess($user, $request->user());

        return response()->json(['success' => true, 'message' => 'One View Super Access granted.', 'data' => new AdminUserResource($user), 'errors' => null]);
    }

    public function revokeOneViewSuperAccess(Request $request, User $user): JsonResponse
    {
        $this->authorize('manage_users');
        $this->users->revokeOneViewSuperAccess($user, $request->user());

        return response()->json(['success' => true, 'message' => 'One View Super Access revoked.', 'data' => new AdminUserResource($user), 'errors' => null]);
    }
}
