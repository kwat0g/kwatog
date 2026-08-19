<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Common\Support\HashIdFilter;
use App\Common\Services\SettingsService;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Enums\StockCountScope;
use App\Modules\Inventory\Resources\StockCountItemResource;
use App\Modules\Inventory\Resources\StockCountSessionResource;
use App\Modules\Inventory\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\InvalidMovementException;

class StockCountController
{
    public function __construct(
        private readonly StockCountService $service,
        private readonly SettingsService $settings,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'scopes' => array_map(static fn (StockCountScope $scope): array => ['value' => $scope->value, 'label' => $scope->label()], StockCountScope::cases()),
            'variance_tolerance_pct' => $this->settings->requiredFloat('inventory.stock_count.variance_tolerance_pct', 0),
            'default_scope' => (string) $this->settings->get('inventory.stock_count.default_scope', ''),
        ]]);
    }

    /**
     * Route params here are plain `{id}`, not model-bound, so nothing decodes
     * the hash for us. Type-hinting `int $id` made every hash a TypeError (500);
     * these resolve the hash the same way route-model binding would, 404ing on
     * an unknown or malformed one.
     */
    private function sessionId(string $id): int
    {
        return HashIdFilter::decode($id, StockCountSession::class) ?? abort(404);
    }

    private function itemId(string $id): int
    {
        return HashIdFilter::decode($id, StockCountItem::class) ?? abort(404);
    }

    public function index(): AnonymousResourceCollection
    {
        return StockCountSessionResource::collection($this->service->listSessions());
    }

    public function show(string $id): StockCountSessionResource
    {
        return new StockCountSessionResource($this->service->getSession($this->sessionId($id)));
    }

    public function store(Request $request): JsonResponse
    {
        // Decoded before validation: `exists:` compares against a bigint column,
        // so an undecoded hash reaches Postgres as 22P02 — a 500, not a 422.
        $request->merge([
            'warehouse_id' => HashIdFilter::decode($request->input('warehouse_id'), Warehouse::class),
            'zone_id'      => HashIdFilter::decode($request->input('zone_id'), WarehouseZone::class),
        ]);

        $data = $request->validate([
            'title'        => 'required|string|max:200',
            'scope'        => ['required', Rule::enum(StockCountScope::class)],
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'zone_id'      => 'nullable|integer|exists:warehouse_zones,id',
        ]);

        try {
            $session = $this->service->createSession($data, $request->user());
        } catch (BusinessRuleException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new StockCountSessionResource($session))
            ->response()->setStatusCode(201);
    }

    public function start(string $id, Request $request): StockCountSessionResource
    {
        // Kept outside the try. This used to be load-bearing: abort(404) raises
        // NotFoundHttpException, which extends RuntimeException, so the old
        // `catch (RuntimeException)` rewrote an unknown id as a 422. The catch is
        // now narrowed to the business rules, so the 404 would survive either
        // way — but resolving before the try still says which failures belong to
        // the id and which to the session's state.
        $sessionId = $this->sessionId($id);

        try {
            $session = $this->service->startSession($sessionId, $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
        return new StockCountSessionResource($session);
    }

    public function recordCount(string $id, Request $request): StockCountItemResource
    {
        $itemId = $this->itemId($id);

        $data = $request->validate([
            'counted_quantity' => 'required|numeric|min:0',
            'lot_number'       => 'nullable|string|max:50',
            'notes'            => 'nullable|string|max:500',
        ]);

        try {
            $item = $this->service->recordCount($itemId, $data, $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
        return new StockCountItemResource($item);
    }

    public function approveVariance(string $id, Request $request): StockCountItemResource
    {
        $itemId = $this->itemId($id);

        try {
            $item = $this->service->approveVariance($itemId, $request->user());
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
        return new StockCountItemResource($item);
    }

    public function complete(string $id, Request $request): StockCountSessionResource
    {
        $sessionId = $this->sessionId($id);

        try {
            $session = $this->service->completeSession($sessionId, $request->user());
        } catch (BusinessRuleException|ClosedPeriodException|InsufficientStockException|InvalidMovementException $e) {
            abort(422, $e->getMessage());
        }
        return new StockCountSessionResource($session);
    }

    public function cancel(string $id): JsonResponse
    {
        $sessionId = $this->sessionId($id);

        try {
            $this->service->cancelSession($sessionId);
        } catch (BusinessRuleException $e) {
            abort(422, $e->getMessage());
        }
        return response()->json(null, 204);
    }
}
