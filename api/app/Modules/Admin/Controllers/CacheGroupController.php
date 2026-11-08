<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Modules\Admin\Services\CacheGroupInvalidationService;
use App\Modules\Auth\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CacheGroupController
{
    public function __construct(private readonly CacheGroupInvalidationService $cacheGroups) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->cacheGroups->groups()]);
    }

    public function clear(Request $request, string $group): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return response()->json(['data' => $this->cacheGroups->clear($group, $actor)]);
    }
}
