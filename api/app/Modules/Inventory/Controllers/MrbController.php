<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Requests\MrbIndexRequest;
use App\Modules\Inventory\Requests\MrbQualityOptionsRequest;
use App\Modules\Inventory\Requests\ReleaseMrbRequest;
use App\Modules\Inventory\Requests\StoreMrbRequest;
use App\Modules\Inventory\Resources\MaterialReviewRecordResource;
use App\Modules\Inventory\Services\QuarantineService;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Quality\Enums\NcrDisposition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    public function index(MrbIndexRequest $request): AnonymousResourceCollection
    {
        return MaterialReviewRecordResource::collection(
            $this->service->list($request->validated())
        );
    }

    public function qualityOptions(MrbQualityOptionsRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->service->qualityOptions(
            (int) $data['item_id'],
            $data['search'] ?? null,
            (int) ($data['per_page'] ?? 50),
        )]);
    }

    public function sourceLots(Request $request): JsonResponse
    {
        $request->validate(['item_id' => 'required|string', 'location_id' => 'required|string']);
        $item = HashIdFilter::decode($request->input('item_id'), Item::class);
        $location = HashIdFilter::decode($request->input('location_id'), WarehouseLocation::class);
        abort_unless($item && $location, 422, 'Choose a valid item and source location.');

        return response()->json(['data' => app(StockLocationSummaryService::class)->lots($item, $location)]);
    }

    public function show(Request $request, MaterialReviewRecord $mrb): MaterialReviewRecordResource
    {
        $record = $this->service->show($mrb);
        $record->setAttribute('release_capabilities', $this->service->releaseCapabilities($record, $request->user()));

        return new MaterialReviewRecordResource($record);
    }

    /** Raise a hold (quarantine stock). */
    public function store(StoreMrbRequest $request): JsonResponse
    {
        try {
            $mrb = $this->service->hold($request->validated(), $request->user(), $request->idempotencyKey());
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
                array_replace(array_intersect_key($data, array_flip(['inspected_quantity', 'evidence_reference', 'recovery_passed', 'concession_approval_reference'])), ['recovery_passed' => $request->boolean('recovery_passed')]),
            );
        } catch (BusinessRuleException|ClosedPeriodException|InsufficientStockException|InvalidMovementException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new MaterialReviewRecordResource($this->service->show($mrb)))->response()->setStatusCode(200);
    }
}
