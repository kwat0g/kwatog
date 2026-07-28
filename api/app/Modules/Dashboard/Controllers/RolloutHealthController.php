<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\RolloutHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RolloutHealthController
{
    public function __construct(private readonly RolloutHealthService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->summary($request->user())]);
    }
}
