<?php

declare(strict_types=1);

namespace App\Modules\Edge\Controllers;

use App\Modules\Edge\Models\EdgeDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EdgeHealthController
{
    public function ping(Request $request): JsonResponse
    {
        /** @var EdgeDevice $device */
        $device = $request->user();
        return response()->json([
            'data' => [
                'device_id'    => $device->hash_id,
                'name'         => $device->name,
                'device_type'  => $device->device_type?->value,
                'server_time'  => now()->toIso8601String(),
                'abilities'    => $request->user()?->currentAccessToken()?->abilities ?? [],
            ],
        ]);
    }
}
