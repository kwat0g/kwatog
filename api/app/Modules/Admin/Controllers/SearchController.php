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
        $query = $request->query('q');
        if (is_string($query)) {
            $query = trim($query);
        }

        // Validate the normalized query so direct API callers get the same
        // minimum-length contract as the palette, which trims before querying.
        $request->merge(['q' => $query]);
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        $query = (string) $validated['q'];

        return response()->json([
            'data'  => $this->service->search($request->user(), $query),
            'query' => $query,
        ]);
    }
}
