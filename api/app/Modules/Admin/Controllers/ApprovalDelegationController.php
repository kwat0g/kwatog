<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Models\ApprovalDelegation;
use App\Modules\Admin\Requests\StoreApprovalDelegationRequest;
use App\Modules\Admin\Resources\ApprovalDelegationResource;
use App\Modules\Admin\Services\ApprovalDelegationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * OGAMI-013 — Approval delegation CRUD.
 *
 * Self-service: a user sets up who covers for them while away. system_admin may
 * manage delegations for anyone. Authorization beyond authentication is enforced
 * in the service (delegator pinning + revoke ownership check).
 */
class ApprovalDelegationController
{
    public function __construct(private readonly ApprovalDelegationService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ApprovalDelegationResource::collection(
            $this->service->list($request->user())
        );
    }

    public function store(StoreApprovalDelegationRequest $request): JsonResponse
    {
        try {
            $delegation = $this->service->create($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new ApprovalDelegationResource($delegation))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, ApprovalDelegation $delegation): JsonResponse
    {
        try {
            $this->service->revoke($delegation, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json([
            'data' => new ApprovalDelegationResource($delegation),
        ]);
    }
}
