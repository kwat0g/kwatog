<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Requests\ReleaseMrbRequest;
use App\Modules\Inventory\Requests\StoreMrbRequest;
use App\Modules\Inventory\Resources\MaterialReviewRecordResource;
use App\Modules\Inventory\Services\QuarantineService;
use App\Modules\Quality\Enums\NcrDisposition;
use App\Modules\Inventory\Enums\MrbStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidMovementException;

/**
 * REC-08 — Material Review Board (hold / release nonconforming stock).
 */
class MrbController
{
    public function __construct(private readonly QuarantineService $service) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'dispositions' => array_map(static fn (NcrDisposition $disposition): array => ['value' => $disposition->value, 'label' => $disposition->label()], NcrDisposition::cases()),
            'statuses' => array_map(static fn (MrbStatus $status): array => ['value' => $status->value, 'label' => $status->label()], MrbStatus::cases()),
        ]]);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return MaterialReviewRecordResource::collection(
            $this->service->list($request->only(['status', 'item_id', 'per_page']))
        );
    }

    public function show(MaterialReviewRecord $mrb): MaterialReviewRecordResource
    {
        return new MaterialReviewRecordResource($this->service->show($mrb));
    }

    /** Raise a hold (quarantine stock). */
    public function store(StoreMrbRequest $request): JsonResponse
    {
        try {
            $mrb = $this->service->hold($request->validated(), $request->user());
        } catch (BusinessRuleException|ClosedPeriodException|InsufficientStockException|InvalidMovementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new MaterialReviewRecordResource($this->service->show($mrb)))
            ->response()->setStatusCode(201);
    }

    /** Release a held MRB per disposition. */
    public function release(ReleaseMrbRequest $request, MaterialReviewRecord $mrb): JsonResponse
    {
        $data = $request->validated();
        try {
            $mrb = $this->service->release(
                $mrb,
                $data['disposition'],
                $request->user(),
                isset($data['target_location_id']) ? (int) $data['target_location_id'] : null,
                $data['notes'] ?? null,
            );
        } catch (BusinessRuleException|ClosedPeriodException|InsufficientStockException|InvalidMovementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new MaterialReviewRecordResource($this->service->show($mrb)))->response()->setStatusCode(200);
    }
}
