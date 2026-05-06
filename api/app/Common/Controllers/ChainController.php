<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Services\ChainRegistry;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * WS-D.1 — Generic chain definition endpoints.
 *
 *  GET /api/v1/chains                 → list every chain key + label
 *  GET /api/v1/chains/{key}/definition → ordered step list for one chain
 *
 * No special permission: chain definitions are taxonomy, not data. Any
 * authenticated user that can see the SPA needs them to render
 * `<ChainHeader>`.
 */
class ChainController
{
    public function __construct(private readonly ChainRegistry $registry) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->registry->all(),
        ]);
    }

    public function definition(string $key): JsonResponse
    {
        if (! $this->registry->has($key)) {
            throw new NotFoundHttpException("Unknown chain key [{$key}].");
        }

        return response()->json([
            'data' => $this->registry->definition($key),
        ]);
    }
}
