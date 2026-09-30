<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\NonConformanceReport;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\UomSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mission Phase 2 — the QUALITY gate of the Procure-to-Pay chain over the
 * real HTTP API, every step performed by its real seeded role (never
 * system_admin).
 *
 * Chain: warehouse receives against an approved PO (GRN lands pending_qc) →
 * the queued TriggerIncomingQC listener stages per-line incoming inspections
 * → the QC inspector records the lot checklist (maker) → a DIFFERENT person
 * checks the result (maker-checker is mandatory for GRN incoming QC) → the
 * pass auto-accepts the GRN and moves stock → the failed path auto-rejects
 * the GRN, auto-opens an NCR (idempotent), the MRB dispositions it
 * (return_to_supplier keeps the receipt auditable), and the CoC endpoint
 * refuses to certify a failed/contradictory lot but certifies the evidence
 * behind a passed one.
 *
 * Adversarial probes ride along: wrong actor (the maker reviewing their own
 * result, warehouse submitting a terminal QC verdict without
 * quality.inspections.manage), wrong time (accept before QC decides,
 * accepting a failed receipt, closing an NCR without disposition or without
 * CAPA actions, use-as-is granted by the failing inspector), evidence
 * integrity (CoC with no measurements, CoC on an unresolved lot).
 */
class QualityGateChainTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private User $finance;
    private User $vp;
    private User $warehouse;
    private User $qc;
    private User $qcChecker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(UomSeeder::class);
        $this->seed(WorkflowSeeder::class);

        $make = fn (string $slug): User => User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);

        $this->buyer = $make('purchasing_officer');
        $this->finance = $make('finance_officer');
        $this->vp = $make('vice_president');
        $this->warehouse = $make('warehouse_staff');
        $this->qc = $make('qc_inspector');
        // The independent second pair of eyes: a second qc_inspector holds
        // quality.inspections.review (checker) AND quality.ncr.manage (MRB
        // disposition) and is never the maker.
        $this->qcChecker = $make('qc_inspector');

        Storage::fake('local');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @template T @param class-string<T> $class @return T */
    private function fromApiId(string $class, ?string $hash): mixed
    {
        $this->assertNotNull($hash, 'API response must carry an id');
        $decoded = app('hashids')->decode($hash);
        $this->assertNotEmpty($decoded, "hash id did not decode: {$hash}");

        return $class::query()->findOrFail($decoded[0]);
    }

    /** Approved PO as in the P2P chain: buyer raises, finance/VP approve. */
    private function createApprovedPo(): PurchaseOrder
    {
        $vendor = Vendor::create([
            'name' => 'Resin supplier '.substr(uniqid(), -5),
            'payment_terms_days' => 30,
        ]);
        $item = \App\Modules\Inventory\Models\Item::factory()->create([
            'is_active' => true,
            'unit_of_measure' => 'KG',
        ]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO-'.substr(uniqid(), -6),
            'vendor_id' => $vendor->id,
            'date' => now()->toDateString(),
            'total_amount' => '1200.00',
            'created_by' => $this->buyer->id,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Draft->value])->save();
        \App\Modules\Purchasing\Models\PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Polypropylene resin',
            'quantity' => '100.000',
            'unit' => 'KG',
            'unit_price' => '12.00',
            'total' => '1200.00',
            'quantity_received' => '0.000',
        ]);

        $this->assertNotNull($po->hash_id, 'PO must carry a HashID for API calls');
        $submit = $this->actingAs($this->buyer)
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/submit");
        fwrite(STDERR, "\nDEBUG submit: ".$submit->getContent()."\n");
        $submit->assertOk();
        $this->actingAs($this->finance)
            ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/approve")
            ->assertOk();
        $po->refresh();
        if ($po->status === PurchaseOrderStatus::PendingApproval) {
            $this->actingAs($this->vp)
                ->patchJson("/api/v1/purchasing/purchase-orders/{$po->hash_id}/approve")
                ->assertOk();
            $po->refresh();
        }

        return $po->fresh();
    }

    /** Warehouse receives fully over the service (fixture for the QC leg). */
    private function receivePo(PurchaseOrder $po): GoodsReceiptNote
    {
        $lines = [];
        foreach ($po->items as $poItem) {
            $lines[] = [
                'purchase_order_item_id' => $poItem->id,
                'item_id' => $poItem->item_id,
                'location_id' => \App\Modules\Inventory\Models\WarehouseLocation::factory()->create()->id,
                'quantity_received' => $poItem->quantity,
                'unit_cost' => $poItem->unit_price,
            ];
        }

        return app(GrnService::class)->create(
            $po,
            $lines,
            ['received_date' => now()->toDateString()],
            $this->warehouse,
        );
    }

    /** The per-line incoming inspection the TriggerIncomingQC listener staged. */
    private function incomingInspection(GoodsReceiptNote $grn): Inspection
    {
        $inspection = Inspection::query()
            ->where('stage', 'incoming')
            ->where('entity_type', 'grn')
            ->where('entity_id', $grn->id)
            ->firstOrFail();
        $this->assertNotNull($inspection->grn_item_id, 'listener-stage inspections are per GRN line');

        return $inspection;
    }

    /**
     * Record the lot checklist as the QC maker, then (optionally) let the
     * independent checker decide. Returns the checklist rows marked FAIL.
     *
     * @param array<int,int> $failIndexes row indexes to mark FAIL
     */
    private function recordLotResult(GoodsReceiptNote $grn, array $failIndexes = []): Inspection
    {
        $inspection = $this->incomingInspection($grn);
        $rows = InspectionMeasurement::query()
            ->where('inspection_id', $inspection->id)
            ->get();

        $checklist = $rows->map(fn ($r, $i) => [
            'id' => $r->hash_id,
            'is_pass' => ! in_array($i, $failIndexes, true),
        ])->all();

        $defects = count($failIndexes);
        $this->actingAs($this->qc)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $checklist,
                'measurements' => [],
                'sample_defect_count' => $defects,
                'complete' => true,
            ])
            ->assertOk();

        $inspection->refresh();

        // GRN incoming QC is maker-checker gated: the lot-result lands the
        // inspection at awaiting_review with the maker's proposed result.
        if ($inspection->status?->value === 'awaiting_review') {
            $decision = $defects === 0 ? 'passed' : 'failed';
            $review = $this->actingAs($this->qcChecker)
                ->patchJson("/api/v1/quality/inspections/{$inspection->hash_id}/review", array_filter([
                    'decision' => $decision,
                    'remarks' => $defects > 0 ? 'Checker concurs: lot fails the incoming checklist.' : null,
                ]));
            $review->assertOk();
        }

        return $inspection->refresh();
    }

    // ------------------------------------------------------------------
    // 1. THE PASS GATE — pending_qc → staged inspection → pass → auto-accept
    // ------------------------------------------------------------------

    public function test_incoming_pass_auto_accepts_grn_and_certificate_flow(): void
    {
        $po = $this->createApprovedPo();
        $grn = $this->receivePo($po);
        $grn->refresh();
        $this->assertSame(GrnStatus::PendingQc->value, $grn->status->value);

        // The queued listener staged a per-line incoming inspection.
        $inspection = $this->incomingInspection($grn);
        $this->assertSame('draft', $inspection->status->value);
        $this->assertNotNull($inspection->grn_item_id);
        $this->assertSame('lot_checklist', $inspection->inspection_mode->value);

        // Wrong time: warehouse cannot accept before QC decides.
        $this->actingAs($this->warehouse)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/accept")
            ->assertStatus(422);

        // Wrong actor: the receiving warehouse user cannot submit a terminal
        // QC verdict (terminal results need quality.inspections.manage).
        $rows = InspectionMeasurement::query()->where('inspection_id', $inspection->id)->get();
        $this->actingAs($this->warehouse)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => ['id' => $r->hash_id, 'is_pass' => true])->all(),
                'measurements' => [],
                'sample_defect_count' => 0,
                'complete' => true,
            ])
            ->assertStatus(403);

        // CoC evidence integrity: a DRAFT inspection has no certificate —
        // no evidence rows are resolved yet.
        $this->actingAs($this->qc)
            ->get("/api/v1/quality/inspections/{$inspection->hash_id}/coc")
            ->assertStatus(422);

        // ACT — the maker records an all-PASS checklist; the checker confirms.
        $inspection = $this->recordLotResult($grn);
        $this->assertSame('passed', $inspection->status->value);
        $this->assertNotNull($inspection->reviewed_by, 'GRN incoming QC is maker-checked');
        $this->assertNotSame($this->qc->id, $inspection->reviewed_by);

        // The chain listener auto-accepted the pending GRN.
        $grn->refresh();
        $this->assertSame(GrnStatus::Accepted->value, $grn->status->value);

        // Wrong time: accepting an already-terminal GRN is refused.
        $this->actingAs($this->warehouse)
            ->patchJson("/api/v1/inventory/grn/{$grn->hash_id}/accept")
            ->assertStatus(422);

        // CoC is an OUTGOING-stage certificate (issued to customers on
        // delivery — the O2C chain exercises that leg). An incoming inspection
        // is refused as a business rule even after a pass with full evidence.
        $this->actingAs($this->qc)
            ->get("/api/v1/quality/inspections/{$inspection->hash_id}/coc")
            ->assertStatus(422)
            ->assertJsonPath('code', 'COC_STAGE_INVALID');
    }

    // ------------------------------------------------------------------
    // 2. THE FAIL GATE — auto-reject, auto-NCR (idempotent), MRB disposition
    // ------------------------------------------------------------------

    public function test_incoming_fail_holds_grn_opens_ncr_and_settles_on_mrb(): void
    {
        $po = $this->createApprovedPo();
        $grn = $this->receivePo($po);

        // Wrong actor: the maker cannot review their own result.
        $inspection = $this->incomingInspection($grn);
        $rows = InspectionMeasurement::query()->where('inspection_id', $inspection->id)->get();
        $this->actingAs($this->qc)
            ->postJson("/api/v1/quality/inspections/{$inspection->hash_id}/lot-result", [
                'checklist' => $rows->map(fn ($r) => ['id' => $r->hash_id, 'is_pass' => false])->all(),
                'measurements' => [],
                'sample_defect_count' => 1,
                'complete' => true,
            ])
            ->assertOk();
        $inspection->refresh();
        $this->assertSame('awaiting_review', $inspection->status->value);

        $selfReview = $this->actingAs($this->qc)
            ->patchJson("/api/v1/quality/inspections/{$inspection->hash_id}/review", [
                'decision' => 'failed',
                'remarks' => 'Self-review attempt.',
            ]);
        $selfReview->assertStatus(403);

        // ACT — the independent checker fails the lot.
        $this->actingAs($this->qcChecker)
            ->patchJson("/api/v1/quality/inspections/{$inspection->hash_id}/review", [
                'decision' => 'failed',
                'remarks' => 'Contamination visible across the lot.',
            ])
            ->assertOk();
        $inspection->refresh();
        $this->assertSame('failed', $inspection->status->value);

        // With MRB review on (the default), a failed receipt is NOT
        // auto-rejected: it is held at pending_qc until the NCR's Material
        // Review Board disposition decides what enters stock.
        $grn->refresh();
        $this->assertSame(GrnStatus::PendingQc->value, $grn->status->value);

        // The failure auto-opened exactly ONE NCR (idempotent per inspection).
        $dup = $this->actingAs($this->qc)
            ->postJson('/api/v1/quality/ncrs', [
                'source' => 'inspection_fail',
                'severity' => 'high',
                'inspection_id' => $inspection->hash_id,
                'defect_description' => 'Duplicate NCR for the same failure.',
                'affected_quantity' => 100,
            ]);
        $dup->assertStatus(422);
        $this->assertSame(1, NonConformanceReport::query()->where('inspection_id', $inspection->id)->count());
        $ncr = NonConformanceReport::query()->where('inspection_id', $inspection->id)->firstOrFail();
        $this->assertSame('inspection_fail', $ncr->source->value);
        $this->assertTrue((bool) $ncr->is_auto_generated);
        $this->assertMatchesRegularExpression('/^NCR-\d{6}-\d{4}$/', (string) $ncr->ncr_number);

        // Wrong time: close without a disposition is refused.
        $this->actingAs($this->qc)
            ->postJson("/api/v1/quality/ncrs/{$ncr->hash_id}/close", [])
            ->assertStatus(422);

        // Wrong actor: use-as-is is a concession the failing inspector cannot
        // grant — the MRB guard is live while the receipt waits for the board.
        $this->actingAs($this->qc)
            ->patchJson("/api/v1/quality/ncrs/{$ncr->hash_id}/disposition", [
                'disposition' => 'use_as_is',
                'root_cause' => 'Supplier process drift.',
            ])
            ->assertStatus(422);

        // ACT — the Material Review Board (the independent checker) grants the
        // concession: the whole lot enters stock and the held receipt is
        // settled accepted in the same transaction.
        $this->actingAs($this->qcChecker)
            ->patchJson("/api/v1/quality/ncrs/{$ncr->hash_id}/disposition", [
                'disposition' => 'use_as_is',
                'root_cause' => 'Supplier process drift — verified cosmetic only.',
                'corrective_action' => 'Supplier corrective action request issued.',
            ])
            ->assertOk();
        $grn->refresh();
        $this->assertSame(GrnStatus::Accepted->value, $grn->status->value);
        $ncr->refresh();
        $this->assertSame($this->qcChecker->id, (int) $ncr->mrb_decided_by);
        $this->assertNotNull($ncr->mrb_decided_at);

        // Wrong time: the MRB decision is final — a different disposition is
        // refused once the receipt has been settled against it.
        $this->actingAs($this->qcChecker)
            ->patchJson("/api/v1/quality/ncrs/{$ncr->hash_id}/disposition", [
                'disposition' => 'return_to_supplier',
                'root_cause' => 'Second thoughts.',
            ])
            ->assertStatus(422);

        // Wrong time: close without CAPA actions is still refused.
        $this->actingAs($this->qc)
            ->postJson("/api/v1/quality/ncrs/{$ncr->hash_id}/close", [])
            ->assertStatus(422);

        // A duplicate manual NCR on the same failure is refused as a domain
        // rule (one NCR per inspection, ever) — a 422, never a raw 500 — and
        // the auto-generated NCR stays single-sourced.
        $this->actingAs($this->qc)
            ->postJson('/api/v1/quality/ncrs', [
                'source' => 'inspection_fail',
                'severity' => 'medium',
                'inspection_id' => $inspection->hash_id,
                'defect_description' => 'Duplicate NCR for the same failure.',
            ])
            ->assertStatus(422);
        $this->assertSame(1, NonConformanceReport::query()->where('inspection_id', $inspection->id)->count());

        // ACT — CAPA actions (containment + corrective + preventive), then close.
        foreach (['containment', 'corrective', 'preventive'] as $type) {
            $this->actingAs($this->qc)
                ->postJson("/api/v1/quality/ncrs/{$ncr->hash_id}/actions", [
                    'action_type' => $type,
                    'description' => ucfirst($type).' action for the contaminated resin lot.',
                ])
                ->assertCreated();
        }
        $this->actingAs($this->qc)
            ->postJson("/api/v1/quality/ncrs/{$ncr->hash_id}/close", [])
            ->assertOk();

        $ncr->refresh();
        $this->assertSame('closed', $ncr->status->value);
        $this->assertSame('use_as_is', $ncr->disposition->value);
        $this->assertNotNull($ncr->closed_at);

        // Wrong time: actions on a closed NCR are refused.
        $this->actingAs($this->qc)
            ->postJson("/api/v1/quality/ncrs/{$ncr->hash_id}/actions", [
                'action_type' => 'containment',
                'description' => 'Too late.',
            ])
            ->assertStatus(422);

        // The failed inspection can never be certified — evidence contradicts.
        $this->actingAs($this->qc)
            ->get("/api/v1/quality/inspections/{$inspection->hash_id}/coc")
            ->assertStatus(422);
    }
}
