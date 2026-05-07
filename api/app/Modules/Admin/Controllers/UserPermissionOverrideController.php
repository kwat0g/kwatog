<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Enums\PermissionOverrideType;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Admin\Requests\StoreUserOverrideRequest;
use App\Modules\Admin\Resources\UserPermissionOverrideResource;
use App\Modules\Admin\Services\UserPermissionOverrideService;
use App\Modules\Auth\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Series R — Task R2.
 *
 * Per-user permission override endpoints. Mounted under
 *   /api/v1/admin/users/{user}/overrides
 * with `permission:admin.users.manage_permissions` middleware.
 */
class UserPermissionOverrideController
{
    public function __construct(
        private readonly UserPermissionOverrideService $service,
    ) {}

    public function index(User $user): ResourceCollection
    {
        return UserPermissionOverrideResource::collection(
            $this->service->listActive($user)
        );
    }

    public function store(StoreUserOverrideRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        $override = $this->service->set(
            user:           $user,
            actor:          $request->user(),
            permissionSlug: $data['permission_slug'],
            type:           PermissionOverrideType::from($data['type']),
            reason:         $data['reason'],
            expiresAt:      isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        );

        return (new UserPermissionOverrideResource($override))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(User $user, UserPermissionOverride $override): JsonResponse
    {
        // Defence: ensure the override actually belongs to the route's user.
        abort_unless($override->user_id === $user->id, 404);

        $this->service->remove($override);

        return response()->json(null, 204);
    }
}
