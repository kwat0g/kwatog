<?php

declare(strict_types=1);

namespace App\Common\Controllers;

use App\Common\Services\ChainBottleneckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Series C — Task C5. Endpoint backing the dashboard bottleneck widget.
 *
 * GET /api/v1/chain/bottlenecks
 *   Returns groups of stuck records per bottleneck step, optionally
 *   filtered to the audience(s) the requesting user's role belongs to.
 */
class ChainBottleneckController
{
    public function __construct(private readonly ChainBottleneckService $service) {}

    public function index(Request $request): JsonResponse
    {
        $all = $this->service->detectAll();

        // Optional audience filter — defaults to ALL groups when no filter
        // is supplied; the SPA may pass ?audience=finance_officer to
        // narrow to a single role's bottlenecks.
        $audience = $request->query('audience');
        if (is_string($audience) && $audience !== '') {
            $all = array_filter(
                $all,
                fn (array $rows) => ! empty($rows) && ($rows[0]['audience'] ?? null) === $audience,
            );
        }

        // Build summary counts per group for cheap rendering on the SPA.
        $groups = [];
        $totalCount = 0;
        foreach ($all as $key => $rows) {
            $count = count($rows);
            $totalCount += $count;
            $groups[] = [
                'key'      => $key,
                'label'    => $rows[0]['label']    ?? $key,
                'audience' => $rows[0]['audience'] ?? null,
                'count'    => $count,
                'rows'     => $rows,
            ];
        }

        return response()->json([
            'data' => [
                'total' => $totalCount,
                'groups' => $groups,
            ],
        ]);
    }
}
