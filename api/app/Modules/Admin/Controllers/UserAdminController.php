<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Requests\ChangeUserRoleRequest;
use App\Modules\Admin\Requests\CreateUserRequest;
use App\Modules\Admin\Requests\ListUsersRequest;
use App\Modules\Admin\Resources\AdminUserDetailResource;
use App\Modules\Admin\Resources\AdminUserListResource;
use App\Modules\Admin\Resources\LoginHistoryResource;
use App\Modules\Admin\Services\UserAdminService;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\WelcomeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserAdminController
{
    public function __construct(
        private readonly UserAdminService $service,
    ) {}

    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        $users = $this->service->list($request->validated());
        return AdminUserListResource::collection($users);
    }

    public function show(User $user): AdminUserDetailResource
    {
        return new AdminUserDetailResource($this->service->show($user));
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $user = $this->service->createStandalone([
            'name'    => $payload['name'],
            'email'   => $payload['email'],
            'role_id' => $payload['role_id'],
        ]);

        $tempPassword = (string) request()->attributes->get('temp_password', '');

        if ($payload['send_welcome'] && $tempPassword !== '') {
            $user->notify(new WelcomeNotification($tempPassword));
        }

        return response()->json([
            'message' => 'User created.',
            'data' => [
                'id'    => $user->hash_id,
                'email' => $user->email,
                'name'  => $user->name,
                // Returned ONCE so admin can copy if email delivery is unavailable.
                'temp_password' => $tempPassword !== '' ? $tempPassword : null,
            ],
        ], 201);
    }

    public function unlock(User $user): JsonResponse
    {
        $this->service->unlock($user);
        return response()->json(['message' => 'Account unlocked.']);
    }

    public function deactivate(User $user): JsonResponse
    {
        $this->service->deactivate($user);
        return response()->json(['message' => 'Account deactivated and sessions revoked.']);
    }

    public function activate(User $user): JsonResponse
    {
        $this->service->activate($user);
        return response()->json(['message' => 'Account reactivated.']);
    }

    public function changeRole(ChangeUserRoleRequest $request, User $user): AdminUserDetailResource
    {
        $updated = $this->service->changeRole($user, $request->decodedRoleId());
        return new AdminUserDetailResource($updated->load(['employee.department', 'employee.position']));
    }

    public function resetPassword(User $user): JsonResponse
    {
        $this->service->resetPassword($user);
        return response()->json([
            'message' => 'Password reset. A new temporary password has been emailed to the user.',
            'sent_to' => $user->email,
        ]);
    }

    public function loginHistory(User $user): AnonymousResourceCollection
    {
        return LoginHistoryResource::collection(
            $this->service->loginHistory($user, 50),
        );
    }
}
