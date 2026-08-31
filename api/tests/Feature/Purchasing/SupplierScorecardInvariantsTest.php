<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\SupplierPerformanceSnapshot;
use App\Modules\Purchasing\Services\SupplierPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M038 re-audit (2026-09-01) — scorecard invariants.
 *
 * A supplier scorecard's characteristic failure is a number that looks
 * authoritative while resting on an incomplete or inconsistent base. These
 * cases pin the parts of that base which were measured wrong, plus the
 * delivery-date boundaries the score turns on.
 *
 * NEW-03 / NEW-04 / NEW-06 are true regressions — each was measured failing
 * before the fix and passing after. The tie-determinism and on-time boundary
 * cases are REGRESSION LOCKS: they passed before the change too, and exist so a
 * future edit cannot silently move a boundary or make ranking order depend on
 * insertion order.
 *
 * All dates are pinned. APP_TIMEZONE is Asia/Manila while containers run UTC,
 * so nothing here may depend on now().
 */
class SupplierScorecardInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private SupplierPerformanceService $service;

    private Vendor $vendor;

    private User $user;

    private int $year = 2026;

    private int $month = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SupplierPerformanceService::class);
        $this->vendor = Vendor::factory()->create();
        $this->user = User::factory()->create();
    }

    private function makePo(?string $expected = '2026-01-20', string $date = '2026-01-05'): int
    {
        return (int) DB::table('purchase_orders')->insertGetId([
            'po_number' => 'PO-'.substr(uniqid(), -9),
            'vendor_id' => $this->vendor->id,
            'date' => $date,
            'expected_delivery_date' => $expected,
            'subtotal' => 0, 'vat_amount' => 0, 'total_amount' => 0,
            'is_vatable' => 1, 'status' => 'approved',
            'requires_vp_approval' => 0, 'current_approval_step' => 0,
            'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeGrn(int $poId, string $received = '2026-01-15'): int
    {
        return (int) DB::table('goods_receipt_notes')->insertGetId([
            'grn_number' => 'GRN-'.substr(uniqid(), -8),
            'purchase_order_id' => $poId,
            'vendor_id' => $this->vendor->id,
            'received_date' => $received,
            'received_by' => $this->user->id,
            'status' => 'pending_qc',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makePoItem(int $poId, string $qty, string $received): void
    {
        DB::table('purchase_order_items')->insert([
            'purchase_order_id' => $poId,
            'item_id' => Item::factory()->create()->id,
            'description' => 'regression line',
            'quantity' => $qty, 'unit' => 'pcs',
            'unit_price' => '10.00', 'total' => '100.00',
            'quantity_received' => $received, 'quantity_accepted' => $received,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function snapshotFor(Vendor $vendor, float $score, ?string $tier): void
    {
        SupplierPerformanceSnapshot::query()->create([
            'vendor_id' => $vendor->id,
            'period_year' => $this->year,
            'period_month' => $this->month,
            'overall_score' => $score,
            'tier' => $tier,
            'po_count' => 1, 'grn_count' => 1,
            'computed_at' => now(),
        ]);
    }

    // ── NEW-03 — archived purchase orders must not feed a live score ─────────

    /**
     * Measured before the fix: adding one SOFT-DELETED, wholly unreceived PO
     * moved price_variance_pct from 0.00 to 50.00 and po_count from 1 to 2.
     * An archived order cannot be received against, so it may not be scored.
     */
    public function test_soft_deleted_purchase_order_does_not_affect_price_variance_or_po_count(): void
    {
        $live = $this->makePo();
        $this->makePoItem($live, '100.00', '100.00');

        $before = $this->service->compute($this->vendor, $this->year, $this->month);
        $this->assertSame('0.00', (string) $before->price_variance_pct);
        $this->assertSame(1, $before->po_count);

        $archived = $this->makePo();
        $this->makePoItem($archived, '100.00', '0.00');
        DB::table('purchase_orders')->where('id', $archived)->update(['deleted_at' => now()]);

        $after = $this->service->compute($this->vendor, $this->year, $this->month);

        $this->assertSame(
            '0.00',
            (string) $after->price_variance_pct,
            'A soft-deleted PO must not contribute ordered quantity to price variance.',
        );
        $this->assertSame(1, $after->po_count, 'A soft-deleted PO must not be counted.');
    }

    /**
     * A soft-deleted PO line is the same defect one level down: the line is
     * reached through a raw join that does not apply the model's scope.
     */
    public function test_soft_deleted_purchase_order_item_does_not_affect_price_variance(): void
    {
        $po = $this->makePo();
        $this->makePoItem($po, '100.00', '100.00');

        $this->makePoItem($po, '400.00', '0.00');
        DB::table('purchase_order_items')
            ->where('purchase_order_id', $po)
            ->where('quantity', '400.00')
            ->update(['deleted_at' => now()]);

        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        $this->assertSame(
            '0.00',
            (string) $snapshot->price_variance_pct,
            'A soft-deleted PO line must not contribute ordered quantity.',
        );
    }

    /**
     * An archived PO must read as "no promised date" — its receipt drops out of
     * the on-time ratio entirely rather than being measured against an order
     * that no longer exists, and rather than counting as late.
     */
    public function test_soft_deleted_purchase_order_is_excluded_from_on_time_delivery(): void
    {
        $po = $this->makePo(expected: '2026-01-01'); // receipt would be LATE
        $this->makeGrn($po, received: '2026-01-15');
        DB::table('purchase_orders')->where('id', $po)->update(['deleted_at' => now()]);

        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        $this->assertNull(
            $snapshot->on_time_delivery_rate,
            'An archived PO must not supply the promised date that decides on-time.',
        );
        $this->assertNull(
            $snapshot->lead_time_variance_days,
            'An archived PO must not supply the anchor date for lead-time variance.',
        );
    }

    // ── NEW-04 — an archived vendor must not keep its ranking slot ───────────

    /**
     * Measured before the fix: the raw leftJoin('vendors') did not filter
     * deleted_at while with('vendor:id,name') did, so the archived vendor
     * ranked FIRST on a 95.00 score and rendered as id=null, name=null.
     */
    public function test_ranking_excludes_soft_deleted_vendors(): void
    {
        $liveVendor = Vendor::factory()->create(['name' => 'AAA Live Vendor']);
        $archivedVendor = Vendor::factory()->create(['name' => 'BBB Archived Vendor']);

        $this->snapshotFor($liveVendor, 70.00, 'C');
        $this->snapshotFor($archivedVendor, 95.00, 'A'); // would outrank the live one

        $archivedVendor->delete();

        $rows = $this->service->ranking($this->year, $this->month, null, 50);

        $this->assertCount(1, $rows, 'An archived vendor must not occupy a ranking slot.');
        $this->assertSame((int) $liveVendor->id, (int) $rows->first()->vendor_id);
        $this->assertNotNull(
            $rows->first()->vendor,
            'Every ranked row must resolve to a vendor; a null identity means the join and the eager load disagree.',
        );
    }

    // ── NEW-06 — lead-time variance must stay inside numeric(5,2) ────────────

    /**
     * Measured before the fix: a PO whose expected date is backdated years
     * produces a variance beyond the column's ±999.99 domain and aborted the
     * whole snapshot with SQLSTATE[22003] numeric field overflow.
     *
     * The clamp is score-neutral: the composite floors this component at 0 for
     * any variance past 20 days, so 999.99 and the true value score the same.
     */
    public function test_extreme_lead_time_variance_is_clamped_instead_of_overflowing(): void
    {
        $po = $this->makePo(expected: '2020-01-01', date: '2026-01-05');
        $this->makeGrn($po, received: '2026-01-15');

        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        $this->assertSame('999.99', (string) $snapshot->lead_time_variance_days);
        $this->assertNotNull($snapshot->overall_score, 'The snapshot must still compute.');
        $this->assertSame(
            '17.50',
            (string) $snapshot->overall_score,
            'Clamping must not change the score: this component is already floored at 0.',
        );
    }

    // ── REGRESSION LOCKS — these passed before the change too ───────────────

    /**
     * LOCK (passes either way). Ranking ties must order by vendor name, never by
     * insertion order. Inserted Zeta-then-Alpha; Alpha must still come first.
     */
    public function test_ranking_tie_is_name_ordered_when_zeta_inserted_first(): void
    {
        $this->assertTieOrder(['ZZZ Zeta Co', 'AAA Alpha Co']);
    }

    /**
     * LOCK (passes either way). The same tie, inserted in the opposite order,
     * must produce byte-identical output.
     */
    public function test_ranking_tie_is_name_ordered_when_alpha_inserted_first(): void
    {
        $this->assertTieOrder(['AAA Alpha Co', 'ZZZ Zeta Co']);
    }

    private function assertTieOrder(array $insertionOrder): void
    {
        foreach ($insertionOrder as $name) {
            $this->snapshotFor(Vendor::factory()->create(['name' => $name]), 88.00, 'B');
        }

        $ranked = $this->service->ranking($this->year, $this->month, null, 50)
            ->map(fn ($row) => $row->vendor?->name)
            ->all();

        $this->assertSame(
            ['AAA Alpha Co', 'ZZZ Zeta Co'],
            $ranked,
            'Equal scores must break by vendor name, independent of insertion order.',
        );
    }

    /**
     * LOCK (passes either way). A delivery arriving EXACTLY on the promised date
     * is on time — the off-by-one that would make it late is the whole point.
     */
    public function test_receipt_exactly_on_promised_date_counts_as_on_time(): void
    {
        $po = $this->makePo(expected: '2026-01-20');
        $this->makeGrn($po, received: '2026-01-20');

        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        $this->assertSame('100.00', (string) $snapshot->on_time_delivery_rate);
    }

    /**
     * LOCK (passes either way). An early receipt is on time; a late one is not.
     */
    public function test_early_receipt_is_on_time_and_late_receipt_is_not(): void
    {
        $this->makeGrn($this->makePo(expected: '2026-01-20'), received: '2026-01-10');
        $this->makeGrn($this->makePo(expected: '2026-01-10'), received: '2026-01-21');

        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        $this->assertSame('50.00', (string) $snapshot->on_time_delivery_rate);
    }

    /**
     * LOCK (passes either way). A receipt whose PO carries no promised date is
     * excluded from BOTH sides of the ratio — it must not be scored as late.
     */
    public function test_receipt_with_no_promised_date_is_excluded_not_counted_late(): void
    {
        $this->makeGrn($this->makePo(expected: null));

        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        $this->assertNull($snapshot->on_time_delivery_rate);
    }

    /**
     * LOCK (passes either way). A vendor with no POs, no receipts and no
     * inspections must return NULL everywhere — never a fabricated 0 — so that
     * "no history" stays distinguishable from "scored zero", and no ratio
     * divides by an empty base.
     */
    public function test_vendor_with_no_history_returns_null_not_zero(): void
    {
        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        $this->assertNull($snapshot->on_time_delivery_rate);
        $this->assertNull($snapshot->quality_pass_rate);
        $this->assertNull($snapshot->ncr_rate);
        $this->assertNull($snapshot->price_variance_pct);
        $this->assertNull($snapshot->lead_time_variance_days);
        $this->assertNull($snapshot->overall_score);
        $this->assertNull($snapshot->tier);
        $this->assertSame(0, $snapshot->po_count);
        $this->assertSame(0, $snapshot->grn_count);
    }
}
