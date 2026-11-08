<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Support\E2EClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class E2EClockController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['now' => now()->toIso8601String()]);
    }

    public function freeze(Request $request, E2EClock $clock): JsonResponse
    {
        $instant = (string) $request->input('instant', '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $instant)) {
            return response()->json(['message' => 'instant must be an ISO-8601 timestamp with a timezone.'], 422);
        }

        try {
            $frozen = $clock->freeze($instant);
        } catch (\Throwable) {
            return response()->json(['message' => 'instant is not a valid date and time.'], 422);
        }

        return response()->json(['now' => $frozen->toIso8601String()]);
    }

    public function clear(E2EClock $clock): JsonResponse
    {
        $clock->clear();

        return response()->json(['now' => CarbonImmutable::now()->toIso8601String()]);
    }
}
