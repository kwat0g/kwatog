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
            // A `warehouse`/`zone` scope whose id is absent used to fall through
            // createSession()'s `elseif` chain and select EVERY active location —
            // a silently company-wide count that also freezes every location it
            // touched. The scope's own id is required for that scope, and a zone
            // must belong to the warehouse it is counted under.
            'warehouse_id' => [
                Rule::requiredIf(static fn (): bool => $request->input('scope') === StockCountScope::Warehouse->value),
                'nullable', 'integer', 'exists:warehouses,id',
            ],
            'zone_id' => [
                Rule::requiredIf(static fn (): bool => $request->input('scope') === StockCountScope::Zone->value),
                'nullable', 'integer',
                Rule::exists('warehouse_zones', 'id')->where(function ($query) use ($request) {
                    // Closure form on purpose: `->where('col', $value)` is
                    // string-serialised by Laravel and would compare a bigint
                    // column against a quoted literal.
                    $warehouseId = $request->input('warehouse_id');
                    if ($warehouseId !== null && $warehouseId !== '') {
                        $query->where('warehouse_id', (int) $warehouseId);
                    }
                }),
            ],
        ], [
            'warehouse_id.required' => 'A warehouse is required for a warehouse-scoped count.',
            'zone_id.required'      => 'A zone is required for a zone-scoped count.',
            'zone_id.exists'        => 'The selected zone does not belong to the selected warehouse.',
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
            // `numeric` admitted scientific notation: `1e3` reached bcsub() as
            // a malformed operand (ValueError → 500), and `1e17` overflowed
            // stock_count_items.counted_quantity numeric(15,3) as 22003.
            // `decimal:0,3` + max matches the column and answers 422 instead.
            'counted_quantity' => 'required|decimal:0,3|min:0|max:999999999999.999',
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
