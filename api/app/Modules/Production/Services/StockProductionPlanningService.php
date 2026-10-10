<?php

declare(strict_types=1);

namespace App\Modules\Production\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\DocumentSequenceService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\MRP\Services\BomService;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Services\PurchaseRequestService;
use App\Modules\Quality\Services\InspectionSpecService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockProductionPlanningService
{
    public function productReadiness(Product $product): array
    {
        $blockers = [];
        $mapping = Item::where('code', $product->part_number)->where('is_active', true)->where('item_type', 'finished_good')->get();
        if (! $product->is_active || $mapping->count() !== 1 || strtolower((string) $mapping->first()?->unit_of_measure) !== 'pcs' || strtolower((string) $product->unit_of_measure) !== 'pcs') {
            $blockers[] = 'Configure one active finished-good inventory item in pieces for this product.';
        }
        $boms = app(BomService::class);
        $bom = $boms->activeForProduct($product->id);
        if (! $bom) {
            $blockers[] = 'Configure an active BOM before planning stock production.';
        } else {
            try { $boms->assertComponentIntegrity($bom); }
            catch (BusinessRuleException $error) { $blockers[] = $error->getMessage(); }
        }
        try {
            $product->loadMissing('inspectionSpec.items', 'inspectionSpec.currentRevision');
            app(InspectionSpecService::class)->assertInspectable($product->inspectionSpec);
        } catch (BusinessRuleException $error) { $blockers[] = $error->getMessage(); }
        return ['eligible' => $blockers === [], 'blockers' => $blockers];
    }

    public function create(array $data, User $actor): WorkOrder
    {
        $key = strtolower((string) ($data['request_key'] ?? ''));
        if (! Str::isUuid($key) || ! empty($data['sales_order_id']) || ! empty($data['sales_order_item_id'])
            || ! preg_match('/^[1-9]\d*$/D', (string) ($data['quantity_target'] ?? ''))) {
            throw new BusinessRuleException('Stock production needs a UUID request key and positive whole-piece quantity without a Sales Order.');
        }
        $canonical = array_intersect_key($data, array_flip([
            'product_id', 'quantity_target', 'planned_start', 'planned_end', 'preferred_production_date', 'minimum_workers', 'maximum_workers',
        ])) + ['actor_id' => $actor->id];
        ksort($canonical);
        $fingerprint = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $actor, $key, $fingerprint): WorkOrder {
            // Serialize all stock planning/allocation before taking product, QC and stock locks.
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            $prior = WorkOrder::withTrashed()->where('stock_creation_request_key', $key)->first();
            if ($prior) {
                if ($prior->trashed() || ! hash_equals((string) $prior->stock_creation_fingerprint, $fingerprint)) {
                    throw new BusinessRuleException('This stock creation key already belongs to another payload or archived work order.');
                }
                $this->reconcile($prior, $actor);

                return app(WorkOrderService::class)->show($prior);
            }
            if (Carbon::parse($data['planned_end'])->lte(now()) || (int) $data['quantity_target'] > 2147483647
                || (int) ($data['minimum_workers'] ?? 1) < 1 || (int) ($data['maximum_workers'] ?? 4) > 4
                || (int) ($data['maximum_workers'] ?? 4) < (int) ($data['minimum_workers'] ?? 1)) {
                throw new BusinessRuleException('Stock deadline must be future, quantity within production limits, and workers between 1 and 4.');
            }
            $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
            $readiness = $this->productReadiness($product);
            if (! $readiness['eligible']) throw new BusinessRuleException(implode(' ', $readiness['blockers']));
            $boms = app(BomService::class);
            $boms->assertComponentIntegrity($boms->ensureFreshForPlanning($boms->activeForProduct($product->id)));
            $root = app(WorkOrderService::class)->createDraft(array_replace($data, [
                'production_purpose' => 'stock', 'stock_creation_request_key' => $key,
                'stock_creation_fingerprint' => $fingerprint, 'created_by' => $actor->id,
            ]));
            $this->reconcile($root, $actor);

            return app(WorkOrderService::class)->show($root->fresh());
        }, 3);
    }

    public function reconcile(WorkOrder $root, User $actor): array
    {
        return DB::transaction(function () use ($root, $actor): array {
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            $root = WorkOrder::query()->lockForUpdate()->findOrFail($root->id);
            if ($root->production_purpose !== 'stock' || $root->sales_order_id || $root->status->value !== 'planned'
                || $root->currentProductionSchedule()->exists() || $root->materials()->where('actual_quantity_issued', '>', 0)->exists()) {
                return [];
            }
            $available = [];
            $plan = app(BomService::class)->productionPlan($root->product_id, (float) $root->quantity_target,
                function (int $productId, int $itemId, float $gross) use (&$available): float {
                    $available[$itemId] ??= max(0, (float) app(StockLocationSummaryService::class)->availability(
                        StockLevel::where('item_id', $itemId)->with('location.zone.warehouse')->get())['usable_quantity'] - (float) Item::findOrFail($itemId)->safety_stock);
                    $take = min($gross, $available[$itemId]);
                    $available[$itemId] -= $take;

                    return $gross - $take;
                });
            app(WorkOrderService::class)->reconcilePlannedMaterialPlan($root);
            $this->children($plan['subassemblies'], $root, $actor);
            $shortages = [];
            foreach ($plan['materials'] as $row) {
                $item = Item::findOrFail($row['item_id']);
                $supply = app(\App\Modules\Purchasing\Services\OpenSupplyService::class)->stockPlanningSupply($item->id, $root->id);
                $free = $supply['available_to_root'];
                $needed = bcsub($row['gross_quantity'], bccomp($free, '0', 3) < 0 ? '0.000' : $free, 3);
                if (bccomp($needed, '0', 3) > 0) {
                    if (bccomp((string) $item->minimum_order_quantity, '0', 3) > 0) {
                        $multiple = bcdiv($needed, (string) $item->minimum_order_quantity, 8);
                        $needed = bcmul((string) ceil((float) $multiple), (string) $item->minimum_order_quantity, 3);
                    }
                    $shortages[] = [$item, $needed];
                }
            }
            $request = PurchaseRequest::where('stock_production_work_order_id', $root->id)->where('status', '!=', 'cancelled')->lockForUpdate()->first();
            if ($shortages !== [] && ! $request) {
                $request = PurchaseRequest::create([
                    'pr_number' => app(DocumentSequenceService::class)->generate('pr'),
                    'requested_by' => $actor->id, 'department_id' => DB::table('departments')->where('code', 'PPC')->value('id'),
                    'date' => today(), 'required_delivery_date' => $root->planned_end->toDateString(),
                    'reason' => "Materials for stock production {$root->wo_number}", 'priority' => 'normal',
                    'is_auto_generated' => true, 'stock_production_work_order_id' => $root->id,
                ]);
                $request->forceFill(['status' => 'draft'])->save();
            }
            if ($request && ($request->status?->value ?? 'draft') === 'draft' && ! $request->purchaseOrders()->exists() && ! $request->rfqs()->exists()) {
                if ($shortages === []) {
                    app(PurchaseRequestService::class)->cancel($request);

                    return ['material_shortages' => 0];
                }
                $request->items()->delete();
                foreach ($shortages as [$item, $quantity]) {
                    $request->items()->create([
                        'item_id' => $item->id, 'description' => $item->name, 'quantity' => $quantity,
                        'unit' => $item->unit_of_measure, 'estimated_unit_price' => $item->standard_cost ?? '0',
                        'purpose' => "Stock work order {$root->wo_number}",
                    ]);
                }
            }

            if ($shortages !== [] && $request && ($request->status->value !== 'draft' || $request->purchaseOrders()->exists() || $request->rfqs()->exists())) {
                throw new BusinessRuleException("Procurement must review additional material requirements against {$request->pr_number} before replanning.");
            }
            return ['material_shortages' => count($shortages)];
        }, 3);
    }

    private function children(array $nodes, WorkOrder $parent, User $actor): void
    {
        $needed = collect($nodes)->filter(fn ($node) => bccomp($node['quantity'], '0', 3) > 0);
        foreach ($parent->children()->where('status', '!=', 'cancelled')->whereNotIn('product_id', $needed->pluck('product_id'))->lockForUpdate()->get() as $obsolete) {
            if ($obsolete->status->value === 'completed' || $obsolete->status->value === 'closed') {
                continue;
            }
            $this->retireUntouched($obsolete);
        }
        foreach ($needed as $node) {
            $child = WorkOrder::where('parent_wo_id', $parent->id)->where('product_id', $node['product_id'])->where('status', '!=', 'cancelled')->lockForUpdate()->first();
            $target = (int) ceil((float) $node['quantity']);
            if (! $child) {
                $child = app(WorkOrderService::class)->createDraft([
                    'product_id' => $node['product_id'], 'quantity_target' => $target,
                    'planned_start' => $parent->planned_start, 'planned_end' => $parent->planned_end,
                    'parent_wo_id' => $parent->id, 'production_purpose' => 'stock', 'created_by' => $actor->id,
                ]);
            }
            if ($child->status->value === 'planned' && ! $child->currentProductionSchedule()->exists()
                && ! $child->materials()->where('actual_quantity_issued', '>', 0)->exists()) {
                $child->forceFill(['quantity_target' => $target])->save();
                app(WorkOrderService::class)->reconcilePlannedMaterialPlan($child);
                $this->children($node['children'], $child, $actor);
            } elseif ((int) $child->quantity_target !== $target) {
                throw new BusinessRuleException('Started subassembly work prevents this stock-plan change. Complete or review its commitment first.');
            }
        }
    }

    private function retireUntouched(WorkOrder $child): void
    {
        if ($child->status->value !== 'planned' || $child->currentProductionSchedule()->exists()
            || $child->outputs()->exists() || $child->materials()->where('actual_quantity_issued', '>', 0)->exists()) {
            throw new BusinessRuleException('Committed subassembly work prevents this stock-plan change.');
        }
        foreach ($child->children()->lockForUpdate()->get() as $descendant) {
            $this->retireUntouched($descendant);
        }
        app(WorkOrderService::class)->cancel($child, 'Untouched subassembly no longer required by its stock production plan.');
    }
}
