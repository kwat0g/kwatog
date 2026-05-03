<?php

declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Common\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sprint 8 — Task 75. */
class SearchController
{
    public function __construct(private readonly GlobalSearchService $service) {}

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        return response()->json([
            'data'  => $this->service->search($request->user(), (string) $request->query('q')),
            'query' => (string) $request->query('q'),
        ]);
    }
}
