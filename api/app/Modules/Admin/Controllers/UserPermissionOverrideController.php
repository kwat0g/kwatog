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
use Illuminate\Http\Request;
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

    public function index(Request $request, User $user): ResourceCollection
    {
        return UserPermissionOverrideResource::collection(
            $this->service->listActive($user, $request->user(), $request->boolean('include_deleted'))
        );
    }

    public function store(StoreUserOverrideRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        $override = $this->service->set(
            user: $user,
            actor: $request->user(),
            permissionSlug: $data['permission_slug'],
            type: PermissionOverrideType::from($data['type']),
            reason: $data['reason'],
            expiresAt: isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
        );

        return (new UserPermissionOverrideResource($override))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, User $user, UserPermissionOverride $override): JsonResponse
    {
        // Defence: ensure the override actually belongs to the route's user.
        abort_unless($override->user_id === $user->id, 404);

        $this->service->remove($override, $request->user());

        return response()->json(null, 204);
    }

    public function restore(Request $request, User $user, UserPermissionOverride $override): JsonResponse
    {
        // Restore must remain scoped to the user in the URL, just like delete.
        abort_unless($override->user_id === $user->id, 404);
        $this->service->restore($user, $override, $request->user());

        return response()->json(['message' => 'User permission override restored.']);
    }
}
