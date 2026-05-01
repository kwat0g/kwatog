<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

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
}
