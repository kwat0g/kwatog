<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Services\EmailDeliveryFailureNotifier;
use App\Modules\Admin\Enums\AdminUserStatus;
use App\Modules\Admin\Requests\BulkChangeUserRoleRequest;
use App\Modules\Admin\Requests\ChangeUserRoleRequest;
use App\Modules\Admin\Requests\CreateUserRequest;
use App\Modules\Admin\Requests\ListUsersRequest;
use App\Modules\Admin\Requests\UpdateUserProfileRequest;
use App\Modules\Admin\Resources\AdminUserDetailResource;
use App\Modules\Admin\Resources\AdminUserListResource;
use App\Modules\Admin\Resources\LoginHistoryResource;
use App\Modules\Admin\Services\UserAdminService;
use App\Modules\Auth\Models\User;
use App\Modules\Auth\Notifications\WelcomeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'statuses' => array_map(
                static fn (AdminUserStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                AdminUserStatus::cases(),
            ),
            ...$this->service->options($request->user()),
        ]]);
    }

    public function show(User $user): AdminUserDetailResource
    {
        return new AdminUserDetailResource($this->service->show($user));
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $payload = $request->payload();
        $created = $this->service->createStandalone([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'role_id' => $payload['role_id'],
        ], $request->user());

        if ($payload['send_welcome'] && $created->tempPassword !== '') {
            try {
                $created->user->notify(new WelcomeNotification($created->tempPassword));
            } catch (\Throwable $e) {
                app(EmailDeliveryFailureNotifier::class)->notifyPermission(
                    'admin.users.manage',
                    'User welcome email',
                    "The welcome email for {$created->user->name} ({$created->user->email}) could not be delivered. Use the returned temporary password through an approved channel.",
                    [
                        'link_to' => '/admin/users/'.$created->user->hash_id,
                        'entity_type' => 'user',
                        'entity_id' => $created->user->hash_id,
                        'reason' => 'The email provider rejected or could not deliver the welcome message.',
                    ],
                );
            }
        }

        return response()->json([
            'message' => 'User created.',
            'data' => [
                'id' => $created->user->hash_id,
                'email' => $created->user->email,
                'name' => $created->user->name,
                // Returned ONCE so admin can copy if email delivery is unavailable.
                'temp_password' => $created->tempPassword !== '' ? $created->tempPassword : null,
            ],
        ], 201);
    }

    public function unlock(User $user): JsonResponse
    {
        $this->service->unlock($user, request()->user());

        return response()->json(['message' => 'Account unlocked.']);
    }

    public function deactivate(User $user): JsonResponse
    {
        $this->service->deactivate($user, request()->user());

        return response()->json(['message' => 'Account deactivated and sessions revoked.']);
    }

    public function activate(User $user): JsonResponse
    {
        $this->service->activate($user, request()->user());

        return response()->json(['message' => 'Account reactivated.']);
    }

    public function changeRole(ChangeUserRoleRequest $request, User $user): AdminUserDetailResource
    {
        $updated = $this->service->changeRole(
            $user,
            $request->decodedRoleId(),
            $request->decodedExpectedRoleId(),
            $request->reason(),
            $request->user(),
        );

        return new AdminUserDetailResource($updated->load(['employee.department', 'employee.position']));
    }

    public function updateProfile(UpdateUserProfileRequest $request, User $user): AdminUserDetailResource
    {
        $updated = $this->service->updateProfile($user, $request->payload(), $request->user());

        return new AdminUserDetailResource($updated->load(['employee.department', 'employee.position']));
    }

    public function resetPassword(User $user): JsonResponse
    {
        $temporaryPassword = $this->service->resetPassword($user, request()->user());

        return response()->json([
            'message' => 'Password reset. Copy the one-time temporary password shown here and share it through an approved channel.',
            'sent_to' => $user->email,
            'temp_password' => $temporaryPassword,
        ]);
    }

    public function loginHistory(User $user): AnonymousResourceCollection
    {
        return LoginHistoryResource::collection(
            $this->service->loginHistory($user, 50),
        );
    }

    public function bulkChangeRole(BulkChangeUserRoleRequest $request): JsonResponse
    {
        $decoded = $request->decodedUserIds();
        $updated = $this->service->bulkChangeRole(
            $decoded['ids'],
            $request->decodedRoleId(),
            $request->reason(),
            $request->decodedExpectedRoleIds(),
            $request->user(),
        );

        return response()->json([
            'message' => 'Roles updated.',
            'data' => [
                'updated' => $updated['updated'],
                'conflicts' => $updated['conflicts'],
                'missing' => $updated['missing'],
                'invalid_ids' => $decoded['invalid'],
            ],
        ]);
    }
}
