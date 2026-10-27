<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\OutboxService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\SearchOperator;
use App\Common\Support\TrashedFilter;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Services\PricingReviewService;
use App\Modules\Inventory\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCategory;
use App\Modules\Inventory\Models\Uom;
use App\Modules\MRP\Events\MrpReplanRequested;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\BomItem;
use App\Modules\MRP\Services\BomCostingService;
use App\Modules\MRP\Services\MrpScopeResolver;
use App\Modules\Production\Models\WorkOrderMaterial;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as StockPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ItemService
{
    public function list(array $filters): LengthAwarePaginator
    {
        $signal = $filters['signal'] ?? null;
        $q = Item::query()->with('category:id,name,parent_id,item_type');
        $q->withExists(['qualityPlans as has_active_quality_plan' => fn ($plan) => $plan->effective()]);
        TrashedFilter::apply($q, $filters);
        if ($signal !== null) {
            // Signals describe active inventory only, even when archive filters are also present.
            $q->whereNull('items.deleted_at')->where('is_active', true);
        }

        // Subqueries for available/on-hand to prevent N+1.
        $q->withSum(['stockLevels as on_hand_quantity' => fn ($s) => $s], 'quantity')
            ->withSum(['stockLevels as reserved_quantity' => fn ($s) => $s], 'reserved_quantity');

        if (! empty($filters['item_type'])) {
            $q->where('item_type', $filters['item_type']);
        }
        if (! empty($filters['category_id'])) {
            $catId = HashIdFilter::decode($filters['category_id'], ItemCategory::class);
            if ($catId) {
                $q->where('category_id', $catId);
            }
        }
        if ($signal === null && isset($filters['is_active']) && $filters['is_active'] !== '') {
            $q->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }
        if (isset($filters['is_critical']) && $filters['is_critical'] !== '') {
            $q->where('is_critical', filter_var($filters['is_critical'], FILTER_VALIDATE_BOOLEAN));
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $q->where(function ($qq) use ($term) {
                $qq->where('code', SearchOperator::like(), SearchOperator::contains($term))
                    ->orWhere('name', SearchOperator::like(), SearchOperator::contains($term));
            });
        }

        $q->with('stockLevels.location.zone.warehouse')->orderBy('code');
        $perPage = max(1, min((int) ($filters['per_page'] ?? 25), 100));
        $status = $filters['stock_status'] ?? null;
        $belowReorder = filter_var($filters['below_reorder'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (in_array($status, ['critical', 'low', 'ok'], true) || $belowReorder || $signal !== null) {
            // ponytail: readiness filters scan matching items; materialize ledger holds if catalog scale makes this slow.
            $items = $q->get()->each(fn (Item $item) => $this->attachAvailability($item))
                ->filter(fn (Item $item) => (! in_array($status, ['critical', 'low', 'ok'], true) || $item->stock_status === $status)
                    && (! $belowReorder || bccomp($item->available, (string) $item->reorder_point, 3) <= 0)
                    && ($signal === null || $item->matchesStockSignal($signal, $item->available)))
                ->values();
            $page = StockPaginator::resolveCurrentPage();

            return new StockPaginator($items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page, [
                'path' => StockPaginator::resolveCurrentPath(), 'query' => request()->query(),
            ]);
        }
        $result = $q->paginate($perPage);
        $result->getCollection()->each(fn (Item $item) => $this->attachAvailability($item));

        return $result;
    }

    public function show(Item $item): Item
    {
        // Same aggregates as list(): without them ItemResource falls back to
        // 0.000 and every item detail read "no stock / critical".
        $item->load(['category', 'approvedSuppliers.vendor:id,name', 'stockLevels.location.zone.warehouse'])
            ->loadSum('stockLevels as on_hand_quantity', 'quantity')
            ->loadSum('stockLevels as reserved_quantity', 'reserved_quantity')
            ->loadExists(['qualityPlans as has_active_quality_plan' => fn ($plan) => $plan->effective()]);

        $this->attachAvailability($item);
        $product = Product::where('part_number', $item->code)->first();
        if ($product && $item->item_type->value === 'finished_good') {
            $line = new \App\Modules\CRM\Models\SalesOrderItem(['product_id' => $product->id]);
            $line->setRelation('product', $product);
            $sources = app(\App\Modules\CRM\Services\SalesOrderSupplyService::class)->sources($line);
            $free = array_reduce($sources, fn ($sum, $row) => bcadd($sum, $row['quantity'], 3), '0.000');
            $incoming = \App\Modules\Production\Models\WorkOrder::where('product_id', $product->id)
                ->where('production_purpose', 'stock')->whereNull('parent_wo_id')
                ->whereIn('status', ['planned', 'confirmed', 'in_progress', 'paused'])
                ->selectRaw('COALESCE(SUM(GREATEST(quantity_target - quantity_produced, 0)), 0) AS incoming')->value('incoming');
            $item->forceFill(['approved_free_stock' => $free, 'incoming_stock_quantity' => (string) $incoming]);
        }
        return $item;
    }

    public function create(array $data): Item
    {
        return DB::transaction(function () use ($data) {
            $data = $this->canonicalizeUom($data);
            $data = $this->canonicalizeClassification($data);

            return Item::create($data)->load('category');
        });
    }

    public function update(Item $item, array $data): Item
    {
        return DB::transaction(function () use ($item, $data) {
            $item = Item::query()->lockForUpdate()->findOrFail($item->getKey());
            $data = $this->canonicalizeUom($data);
            $hasMovements = $item->movements()->exists();
            $hasBomUsage = BomItem::query()->where('item_id', $item->id)->exists()
                || WorkOrderMaterial::query()->where('item_id', $item->id)->exists();
            $mappedProduct = Product::withTrashed()->where('part_number', $item->code)->first();
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $this->assertNoActiveBomDependency($item);
                $this->assertNoOpenPurchasingDependency($item);
            }
            if (isset($data['code']) && $data['code'] !== $item->code && ($hasMovements || $hasBomUsage || $mappedProduct)) {
                throw new BusinessRuleException('Cannot change an item code after a BOM, inventory movement, or linked product uses it.');
            }

            // Item type and base-unit semantics must stay stable after use.
            $typeProtected = $hasMovements || $hasBomUsage || $mappedProduct
                || $item->stockLevels()->where(fn ($query) => $query->where('quantity', '<>', 0)->orWhere('reserved_quantity', '<>', 0))->exists()
                || $item->stockCountItems()->exists();
            if ($typeProtected && isset($data['item_type']) && $data['item_type'] !== $item->item_type->value) {
                throw new BusinessRuleException('Cannot change item type after inventory movements, a BOM, or a linked product uses it.');
            }

            $data = $this->canonicalizeClassification($data, $item);
            if ($typeProtected && isset($data['item_type']) && $data['item_type'] !== $item->item_type->value) {
                throw new BusinessRuleException('Cannot change item type after inventory movements, a BOM, or a linked product uses it.');
            }

            $changesBaseUnit = isset($data['unit_of_measure'])
                && strcasecmp(trim((string) $data['unit_of_measure']), trim((string) $item->unit_of_measure)) !== 0;
            if ($changesBaseUnit && $item->stockAdjustments()
                ->where('status', StockAdjustmentStatus::Pending->value)
                ->exists()) {
                throw new BusinessRuleException('Cannot change the base unit while a stock adjustment is pending.');
            }
            $hasOpenPurchaseOrder = $changesBaseUnit && DB::table('purchase_order_items')
                ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
                ->where('purchase_order_items.item_id', $item->id)
                ->whereNotIn('purchase_orders.status', [
                    PurchaseOrderStatus::Received->value,
                    PurchaseOrderStatus::Closed->value,
                    PurchaseOrderStatus::Cancelled->value,
                ])
                ->exists();
            $hasOpenPurchaseRequest = $changesBaseUnit && DB::table('purchase_request_items')
                ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_items.purchase_request_id')
                ->where('purchase_request_items.item_id', $item->id)
                ->whereIn('purchase_requests.status', [
                    PurchaseRequestStatus::Draft->value,
                    PurchaseRequestStatus::Pending->value,
                    PurchaseRequestStatus::Approved->value,
                ])
                ->exists();
            if ($changesBaseUnit && (
                $hasMovements
                || $item->stockLevels()->where(fn ($query) => $query
                    ->where('quantity', '<>', 0)
                    ->orWhere('reserved_quantity', '<>', 0))->exists()
                || $item->stockCountItems()->exists()
                || $item->uomConversions()->exists()
                || $hasOpenPurchaseOrder
                || $hasOpenPurchaseRequest
                || $hasBomUsage
                || $mappedProduct
            )) {
                throw new BusinessRuleException(
                    'Cannot change the base unit while stock is on hand or reserved, or after a movement, count, open purchasing document, BOM, or UOM conversion uses the item.'
                );
            }

            $costChanged = isset($data['standard_cost']) && bccomp((string) $data['standard_cost'], (string) $item->standard_cost, 4) !== 0;
            if ($costChanged && auth()->user() instanceof User && ! auth()->user()->hasPermission('inventory.costs.approve')) {
                abort(403, 'Finance approval is required to change an existing standard cost.');
            }
            $item->update($data);
            if ($costChanged) {
                app(BomCostingService::class)->invalidateForItems([(int) $item->id]);
                app(PricingReviewService::class)->request(Product::where('part_number', $item->code)->pluck('id')->all(), 'mapped_inventory_cost_changed');
                $salesOrderIds = app(MrpScopeResolver::class)->salesOrderIdsForItems([(int) $item->id]);
                if ($salesOrderIds !== []) {
                    app(OutboxService::class)->record(
                        new MrpReplanRequested($salesOrderIds, 'item_standard_cost_changed', auth()->id()),
                        'mrp:replan:item-cost:'.$item->id.':'.Str::uuid(),
                    );
                }
            }

            return $this->show($item->fresh());
        });
    }

    public function delete(Item $item): void
    {
        DB::transaction(function () use ($item) {
            $locked = Item::query()->lockForUpdate()->findOrFail($item->getKey());
            $this->assertNoActiveBomDependency($locked);
            $this->assertNoOpenPurchasingDependency($locked);
            if (! $locked->canDelete()) {
                throw new BusinessRuleException('Cannot delete an item with stock, movements, open stock counts, or pending stock adjustments. Deactivate instead.');
            }
            $locked->delete();
        });
    }

    private function assertNoOpenPurchasingDependency(Item $item): void
    {
        $order = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->where('purchase_order_items.item_id', $item->id)->whereNull('purchase_orders.deleted_at')
            ->whereNotIn('purchase_orders.status', ['received', 'closed', 'cancelled'])->exists();
        $request = DB::table('purchase_request_items')
            ->join('purchase_requests', 'purchase_requests.id', '=', 'purchase_request_items.purchase_request_id')
            ->where('purchase_request_items.item_id', $item->id)->whereNull('purchase_requests.deleted_at')
            ->whereIn('purchase_requests.status', ['draft', 'pending', 'approved'])->exists();
        if ($order || $request) {
            throw new BusinessRuleException('Cannot retire an item used by an open purchasing document. Complete or cancel the commitment first.');
        }
    }

    private function attachAvailability(Item $item): Item
    {
        $readiness = app(StockLocationSummaryService::class)->availability($item->stockLevels);
        $item->forceFill($readiness)->syncOriginalAttributes(array_keys($readiness));

        return $item;
    }

    private function assertNoActiveBomDependency(Item $item): void
    {
        $dependency = Bom::query()->active()->where(function ($query) use ($item): void {
            $query->whereHas('items', fn ($lines) => $lines->where('item_id', $item->id))
                ->orWhereHas('product', fn ($product) => $product->where('part_number', $item->code));
        })->orderBy('id')->first();
        if ($dependency) {
            throw new BusinessRuleException('Cannot retire item '.$item->code.' while active BOM '.$dependency->hash_id.' version '.$dependency->version.' uses it. Replace or archive the BOM first.');
        }
    }

    private function canonicalizeClassification(array $data, ?Item $item = null): array
    {
        $categoryId = $data['category_id'] ?? $item?->category_id;
        $categoryChanged = ! $item || (int) $categoryId !== (int) $item->category_id;
        if ($item && ! $categoryChanged && (! isset($data['item_type']) || $data['item_type'] === $item->item_type->value)) {
            // Preserve legacy conflicts during unrelated edits; never silently reclassify history.
            return $data;
        }
        // ponytail: serialize rare classification edits across the category catalog; narrow locks if catalog writes become frequent.
        $categories = ItemCategory::withTrashed()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $category = $categories->get($categoryId);
        if (! $category || $category->trashed() || ! $category->item_type) {
            throw ValidationException::withMessages(['category_id' => 'Select an active category with an item type configured.']);
        }
        if (isset($data['item_type']) && $data['item_type'] !== $category->item_type) {
            throw ValidationException::withMessages(['item_type' => 'Item type must match the selected category.']);
        }
        $data['item_type'] = $category->item_type;

        return $data;
    }

    private function canonicalizeUom(array $data): array
    {
        if (! isset($data['unit_of_measure'])) {
            return $data;
        }

        $uom = Uom::query()
            ->whereRaw('LOWER(code) = LOWER(?)', [trim((string) $data['unit_of_measure'])])
            ->lockForUpdate()
            ->first();
        if (! $uom) {
            throw new BusinessRuleException('The selected unit of measure is no longer available.');
        }

        $data['unit_of_measure'] = $uom->code;

        return $data;
    }
}
