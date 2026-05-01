<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Services\RoleService;
use Illuminate\Http\JsonResponse;

class PermissionController
{
    public function __construct(private readonly RoleService $service) {}

    public function matrix(): JsonResponse
    {
        return response()->json([
            'data' => $this->service->permissionMatrix(),
        ]);
    }
}
