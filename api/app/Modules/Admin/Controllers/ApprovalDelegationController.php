<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalDelegation;
use App\Modules\Admin\Requests\StoreApprovalDelegationRequest;
use App\Modules\Admin\Resources\ApprovalDelegationResource;
use App\Modules\Admin\Services\ApprovalDelegationService;
use App\Modules\Auth\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    public function candidates(Request $request): JsonResponse
    {
        $request->merge(['q' => trim((string) $request->query('q', ''))]);
        $search = mb_strtolower((string) $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ])['q']);

        $actor = $request->user();
        $users = User::query()
            ->with('role:id,name')
            ->where('is_active', true)
            ->where('id', '<>', $actor->id)
            ->where(function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $query->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
            })
            ->when($actor->role?->slug === 'department_head', function ($query) use ($actor): void {
                $departmentId = $actor->employee?->department_id;
                $query->where(function ($departmentQuery) use ($departmentId): void {
                    $departmentQuery->whereHas('employee', fn ($employeeQuery) => $employeeQuery
                        ->where('department_id', $departmentId));
                    if ($departmentId === null) {
                        $departmentQuery->orWhereDoesntHave('employee');
                    }
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'role_id']);

        return response()->json([
            'data' => $users->map(fn (User $user) => [
                'id' => $user->hash_id,
                'name' => $user->name,
                'role' => $user->role?->name,
            ])->values(),
        ]);
    }

    public function store(StoreApprovalDelegationRequest $request): JsonResponse
    {
        try {
            $delegation = $this->service->create($request->validated(), $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new ApprovalDelegationResource($delegation))
            ->response()
            ->setStatusCode($delegation->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, ApprovalDelegation $delegation): JsonResponse
    {
        // 403, not 422 — the arm exists to set that status ("you may only revoke
        // your own delegation" is an authorization refusal), so it stays and only
        // the caught type changes. Deleting it would have let the render hook
        // answer 422, quietly downgrading an access decision to a validation
        // complaint.
        try {
            $this->service->revoke($delegation, $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json([
            'data' => new ApprovalDelegationResource($delegation),
        ]);
    }
}
