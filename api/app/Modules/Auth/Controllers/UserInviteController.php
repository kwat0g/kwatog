<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Models\UserInvite;
use App\Modules\Auth\Requests\AcceptUserInviteRequest;
use App\Modules\Auth\Requests\CreateUserInviteRequest;
use App\Modules\Auth\Resources\UserInviteResource;
use App\Modules\Auth\Resources\UserResource;
use App\Modules\Auth\Services\UserInviteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * WS-A.1 — HTTP entrypoints for the portal-account invite lifecycle.
 *
 * - GET    /auth/invites          — paginated list (auth.users.invite)
 * - POST   /auth/invites          — issue an invite (auth.users.invite)
 * - DELETE /auth/invites/{invite} — revoke (auth.users.invite)
 * - POST   /auth/invites/accept   — public accept (no auth)
 */
class UserInviteController
{
    public function __construct(private readonly UserInviteService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $invites = $this->service->list($request->all());

        // Eager-load relations the resource references, so we never trigger
        // lazy-load violations under shouldBeStrict() in non-prod.
        $invites->getCollection()->loadMissing(['employee', 'role', 'inviter']);

        return UserInviteResource::collection($invites);
    }

    public function store(CreateUserInviteRequest $request): JsonResponse
    {
        $invite = $this->service->invite($request->decoded(), $request->user());
        $invite->load(['employee', 'role', 'inviter']);

        return (new UserInviteResource($invite))->response()->setStatusCode(201);
    }

    public function destroy(UserInvite $invite): JsonResponse
    {
        $this->service->revoke($invite);
        return response()->json(null, 204);
    }

    public function accept(AcceptUserInviteRequest $request): UserResource
    {
        $user = $this->service->accept($request->validated());
        $user->load('role');

        return new UserResource($user);
    }
}
