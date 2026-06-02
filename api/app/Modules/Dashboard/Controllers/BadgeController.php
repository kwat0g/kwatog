<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Controllers;

use App\Modules\Dashboard\Services\BadgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Polish Task S2 — Sidebar badge count system. */
class BadgeController
{
    public function __construct(private readonly BadgeService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->for($request->user())]);
    }
}
