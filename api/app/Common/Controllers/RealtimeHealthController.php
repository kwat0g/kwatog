<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Services\RealtimeQueueHealth;
use Illuminate\Http\JsonResponse;

final class RealtimeHealthController
{
    public function __construct(private readonly RealtimeQueueHealth $health) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'delivery' => $this->health->deliveryHealthy() ? 'healthy' : 'degraded',
            ],
        ]);
    }
}
