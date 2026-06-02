<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Requests\CloneRoleRequest;
use App\Modules\Admin\Requests\StoreRoleRequest;
use App\Modules\Admin\Requests\SyncRolePermissionsRequest;
use App\Modules\Admin\Requests\UpdateRoleRequest;
use App\Modules\Admin\Resources\RoleResource;
use App\Modules\Admin\Services\RoleService;
use App\Modules\Auth\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RoleController
{
    public function __construct(private readonly RoleService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return RoleResource::collection($this->service->list($request->all()));
    }

    public function show(Role $role): RoleResource
    {
        return new RoleResource($this->service->show($role));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->service->create($request->validated());
        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(UpdateRoleRequest $request, Role $role): RoleResource
    {
        return new RoleResource($this->service->update($role, $request->validated()));
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->service->delete($role);
        return response()->json(null, 204);
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): RoleResource
    {
        $role = $this->service->syncPermissions($role, $request->validated('permission_slugs'));
        return new RoleResource($role);
    }

    /**
     * R1 — POST /admin/roles/{role}/clone
     */
    public function clone(CloneRoleRequest $request, Role $role): JsonResponse
    {
        $clone = $this->service->clone($role, $request->validated());
        return (new RoleResource($clone))->response()->setStatusCode(201);
    }

    /**
     * ADV4 — GET /admin/roles/compare?a={hashId}&b={hashId}
     *
     * Side-by-side permission diff between two roles. Decodes both hash_ids,
     * resolves the Role models, and returns the categorized diff used by the
     * Compare Roles page.
     */
    public function compare(Request $request): JsonResponse
    {
        $request->validate([
            'a' => ['required', 'string'],
            'b' => ['required', 'string'],
        ]);

        $hashids = app('hashids');
        $decodedA = $hashids->decode((string) $request->query('a'));
        $decodedB = $hashids->decode((string) $request->query('b'));
        $idA = $decodedA[0] ?? abort(404, 'Role A not found.');
        $idB = $decodedB[0] ?? abort(404, 'Role B not found.');

        $a = Role::findOrFail((int) $idA);
        $b = Role::findOrFail((int) $idB);

        return response()->json(['data' => $this->service->compare($a, $b)]);
    }
}
