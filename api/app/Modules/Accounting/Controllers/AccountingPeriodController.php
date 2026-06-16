<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Controllers;

use App\Modules\Accounting\Requests\CloseAccountingPeriodRequest;
use App\Modules\Accounting\Requests\ReopenAccountingPeriodRequest;
use App\Modules\Accounting\Resources\AccountingPeriodResource;
use App\Modules\Accounting\Services\AccountingPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountingPeriodController
{
    public function __construct(private readonly AccountingPeriodService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AccountingPeriodResource::collection($this->service->list($request->query()));
    }

    public function close(CloseAccountingPeriodRequest $request): JsonResponse|AccountingPeriodResource
    {
        $data = $request->validated();
        try {
            $period = $this->service->close((int) $data['year'], (int) $data['month'], $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new AccountingPeriodResource($period->load(['closedBy', 'reopenedBy']));
    }

    public function reopen(ReopenAccountingPeriodRequest $request): JsonResponse|AccountingPeriodResource
    {
        $data = $request->validated();
        try {
            $period = $this->service->reopen(
                (int) $data['year'],
                (int) $data['month'],
                $request->user(),
                (string) $data['reason'],
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        return new AccountingPeriodResource($period->load(['closedBy', 'reopenedBy']));
    }
}
