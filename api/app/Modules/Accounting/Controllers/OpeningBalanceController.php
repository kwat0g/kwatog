<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Requests\StoreOpeningBalanceRequest;
use App\Modules\Accounting\Requests\StoreOpeningStockRequest;
use App\Modules\Accounting\Resources\JournalEntryResource;
use App\Modules\Accounting\Services\OpeningBalanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * REC-05 — go-live opening balances (GL + stock) and TB reconciliation.
 */
class OpeningBalanceController
{
    public function __construct(private readonly OpeningBalanceService $service) {}

    public function postGl(StoreOpeningBalanceRequest $request): JsonResponse
    {
        try {
            $je = $this->service->loadGl($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new JournalEntryResource($je))
            ->additional(['message' => 'Opening balances posted.'])
            ->response()
            ->setStatusCode(201);
    }

    public function postStock(StoreOpeningStockRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            $result = $this->service->loadStock($data['rows'], $data['location_id'], $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data'    => $result,
            'message' => "Loaded {$result['count']} opening-stock line(s).",
        ], 201);
    }

    public function tbMatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lines'              => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required'],
            'lines.*.debit'      => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit'     => ['nullable', 'numeric', 'min:0'],
            'as_of'              => ['nullable', 'date'],
        ]);

        $asOf = ! empty($validated['as_of']) ? Carbon::parse((string) $validated['as_of']) : null;

        return response()->json([
            'data' => $this->service->trialBalanceMatch($validated['lines'], $asOf),
        ]);
    }
}
