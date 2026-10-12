<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\OutboxMessage;
use App\Common\Services\SchedulerExecutionLedger;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\PriceRequest;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCostReview;
use App\Modules\Inventory\Models\WarehouseScanEvent;
use App\Modules\MRP\Events\BomPricingReviewRequested;
use App\Modules\MRP\Models\Bom;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RolloutHealthService
{
    public function __construct(
        private readonly ActionCenterService $actions,
        private readonly SettingsService $settings,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        $eligibleTypes = $this->settings->get('quality.rollout.eligible_item_types', '__missing_quality_rollout_types__');
        if (! is_array($eligibleTypes) || $eligibleTypes === []) {
            throw new BusinessRuleException('Required business setting quality.rollout.eligible_item_types is missing or invalid.');
        }
        $eligible = Item::query()->active()->whereIn('item_type', $eligibleTypes);
        $totalItems = (clone $eligible)->count();
        $missingItems = (clone $eligible)
            ->whereDoesntHave('qualityPlans', fn ($plan) => $plan->effective())
            ->orderBy('code')->get(['id', 'code', 'name', 'is_critical']);

        $grace = $this->settings->requiredInt('quality.rollout.pending_grn_grace_minutes', 0);
        $missingQc = GoodsReceiptNote::query()
            ->where('status', 'pending_qc')
            ->where('created_at', '<=', now()->subMinutes($grace))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('inspections')
                ->leftJoin('grn_items as inspection_grn_items', 'inspection_grn_items.id', '=', 'inspections.grn_item_id')
                ->where(fn ($source) => $source
                    ->whereColumn('inspection_grn_items.goods_receipt_note_id', 'goods_receipt_notes.id')
                    ->orWhere(fn ($legacy) => $legacy
                        ->whereNull('inspections.grn_item_id')
                        ->whereNull('inspections.work_order_output_id')
                        ->whereNull('inspections.return_request_item_id')
                        ->whereNull('inspections.wo_operation_id')
                        ->where('inspections.entity_type', 'grn')
                        ->whereColumn('inspections.entity_id', 'goods_receipt_notes.id'))))
            ->count();

        $since = now()->subDay();
        $scanTotal = WarehouseScanEvent::query()->where('created_at', '>=', $since)->count();
        $unknownScans = WarehouseScanEvent::query()->where('created_at', '>=', $since)
            ->where('is_recognized', false)->count();
        $unknownCodes = WarehouseScanEvent::query()->where('created_at', '>=', $since)
            ->where('is_recognized', false)
            ->select(['barcode', DB::raw('COUNT(*) as occurrences')])
            ->groupBy('barcode')->orderByDesc('occurrences')->limit(10)->get()
            ->map(fn ($row) => ['barcode' => $row->barcode, 'occurrences' => (int) $row->occurrences])->all();

        $failedInspections = Inspection::query()->where('status', 'failed')
            ->where('completed_at', '>=', $since)->count();
        $actionSummary = $this->actions->for($user)['summary'];
        $attention = $missingItems->isNotEmpty() || $missingQc > 0 || $unknownScans > 0 || $actionSummary['overdue'] > 0;
        $scheduler = app(SchedulerExecutionLedger::class)->health(15);
        $lastTick = $scheduler['latest_tick'];
        $pendingPricing = OutboxMessage::where('event_type', BomPricingReviewRequested::class)->whereIn('status', ['pending', 'processing'])->count();
        $failedPricing = OutboxMessage::where('event_type', BomPricingReviewRequested::class)->where('status', 'failed')->count();
        $overduePricing = OutboxMessage::where('event_type', BomPricingReviewRequested::class)->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '<=', now()->subMinutes(15))->count();
        $staleBoms = Bom::active()->whereHas('product', fn ($q) => $q->where('is_active', true))->whereNull('costed_at')->count();
        $attention = $attention || $failedPricing > 0 || $overduePricing > 0 || $staleBoms > 0 || ! $scheduler['healthy'];

        return [
            'status' => $attention ? 'attention' : 'healthy',
            'status_label' => Str::headline($attention ? 'attention' : 'healthy'),
            'quality_plans' => [
                'eligible_items' => $totalItems,
                'covered_items' => $totalItems - $missingItems->count(),
                'coverage_percent' => $totalItems > 0 ? round((($totalItems - $missingItems->count()) / $totalItems) * 100, 1) : null,
                'missing' => $missingItems->map(fn (Item $item) => [
                    'id' => $item->hash_id, 'code' => $item->code, 'name' => $item->name,
                    'is_critical' => (bool) $item->is_critical,
                ])->all(),
            ],
            'qc_triggers' => [
                'pending_grns_without_inspection' => $missingQc,
                'failed_inspections_24h' => $failedInspections,
                'grace_minutes' => $grace,
            ],
            'scanner' => [
                'scans_24h' => $scanTotal,
                'unrecognized_24h' => $unknownScans,
                'recognition_rate' => $scanTotal > 0 ? round((($scanTotal - $unknownScans) / $scanTotal) * 100, 1) : null,
                'top_unrecognized' => $unknownCodes,
            ],
            'actions' => $actionSummary,
            'automation' => ['scheduler_last_tick' => $lastTick?->started_at?->toISOString(), 'scheduler_status' => $lastTick?->status ?? 'missing', 'pending_pricing' => $pendingPricing, 'failed_pricing' => $failedPricing, 'open_price_requests' => PriceRequest::whereIn('status', ['requested', 'pending_finance', 'offered'])->count(), 'pending_cost_reviews' => ItemCostReview::where('status', 'pending')->count()],
            'automation_issues' => ['overdue_pricing' => $overduePricing, 'stale_boms' => $staleBoms],
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
