<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Services\SupplierPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P3.3 — Supplier quality score denominator fix.
 *
 * Bug: qualityMetrics() used $rows->count() (ALL inspections) as denominator,
 * including draft/in_progress rows, which diluted the pass rate.
 *
 * Fix: denominator is now restricted to terminal statuses (passed + failed)
 * so open inspections do not count against the vendor.
 *
 * Scenario that exposes the bug:
 *   8 passed + 1 in_progress + 1 draft = 10 total rows
 *   Bug:  8/10 = 80.00%
 *   Fixed: denominator = 8 passed + 1 failed (none) → 8 terminal;
 *          but here there are 0 failed, so denominator = 8 passed
 *          → 8/8 = 100%?   No — re-read:
 *
 *   The task spec says: 8 passed + 1 in_progress + 1 draft
 *   Terminal = passed + failed. Here failed=0, so terminal=8.
 *   pass rate = 8/8 = 100%? That's not 88.9%.
 *
 *   Re-reading the task spec more carefully:
 *   "a vendor with 8 passed + 1 in_progress + 1 draft scores 80% instead of 88.9%"
 *   88.9% = 8/9. So the expected denominator = 9, numerator = 8.
 *   But 9 would imply one failed row in the terminal set.
 *
 *   Therefore the correct fixture is:
 *     8 passed + 1 failed + 1 in_progress + 1 draft = 11 rows total
 *     Bug:  8/11 = 72.7%  (that's not 80% either…)
 *
 *   Let me re-read: "8 passed + 1 in_progress + 1 draft → 80% instead of 88.9%"
 *   80% = 8/10, 88.9% = 8/9. So the denominator the spec expects is 9, not 10.
 *   That means one of the 10 rows should not be in the denominator (the draft),
 *   and 9 = 8 passed + 1 in_progress. But in_progress is also non-terminal...
 *
 *   Wait — re-reading the bug description again:
 *   "denominator includes ALL inspections (incl. in_progress/draft)"
 *   "fix: restrict DENOMINATOR to TERMINAL statuses only — whereIn('status', ['passed','failed'])"
 *   "8 passed + 1 in_progress + 1 draft → assert pass rate ≈ 88.9% (8/9)"
 *
 *   For 8/9 = 88.9%, the terminal set must have 9 rows.
 *   Terminal = passed + failed. So we need: 8 passed + 1 failed = 9 terminal.
 *   Plus 1 in_progress (or draft) = 10 total. That matches.
 *
 *   Correct fixture:
 *     8 passed + 1 failed + 1 in_progress (or draft) = 10 total
 *     Bug:  8/10 = 80.00%    ✓ (matches "80%")
 *     Fixed: 8/9  = 88.89%  ✓ (matches "88.9%")
 *
 * NOTE: The task says "8 passed + 1 in_progress + 1 draft → 80%". The way to
 * produce both 80% (buggy) AND 88.9% (fixed) simultaneously is:
 *   - 8 passed + 1 failed + 1 in_progress OR draft (NOT both)
 *   Where total=10 gives 80% buggy, and terminal=9 gives 88.9% fixed.
 * We build: 8 passed + 1 failed + 1 draft (= 10 total, 9 terminal).
 */
class SupplierQualityMetricsTest extends TestCase
{
    use RefreshDatabase;

    private SupplierPerformanceService $service;
    private Vendor $vendor;
    private User $user;
    private int $year;
    private int $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SupplierPerformanceService::class);

        // Create a vendor directly (avoid factory chain side-effects).
        $this->vendor = Vendor::factory()->create();

        // Use a fixed recent month so received_date falls inside the period.
        $this->year  = 2026;
        $this->month = 1;

        // A user is needed as received_by FK on GRN.
        $this->user = User::factory()->create();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Create a product row (required FK for inspections).
     */
    private function makeProduct(): int
    {
        return (int) DB::table('products')->insertGetId([
            'part_number'    => 'P-' . uniqid(),
            'name'           => 'Test Product',
            'unit_of_measure' => 'pcs',
            'standard_cost'  => '0.00',
            'is_active'      => 1,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    /**
     * Create a minimal PO (required FK for GRN).
     */
    private function makePo(): int
    {
        return (int) DB::table('purchase_orders')->insertGetId([
            'po_number'              => 'PO-' . uniqid(),
            'vendor_id'              => $this->vendor->id,
            'date'                   => '2026-01-05',
            'expected_delivery_date' => '2026-01-20',
            'subtotal'               => 0,
            'vat_amount'             => 0,
            'total_amount'           => 0,
            'is_vatable'             => 1,
            'status'                 => 'approved',
            'requires_vp_approval'   => 0,
            'current_approval_step'  => 0,
            'created_by'             => $this->user->id,
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);
    }

    /**
     * Create a GRN tied to this vendor within the test period.
     */
    private function makeGrn(int $poId): int
    {
        return (int) DB::table('goods_receipt_notes')->insertGetId([
            'grn_number'        => 'GRN-' . uniqid(),
            'purchase_order_id' => $poId,
            'vendor_id'         => $this->vendor->id,
            'received_date'     => '2026-01-15', // inside Jan 2026
            'received_by'       => $this->user->id,
            'status'            => 'pending_qc',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /**
     * Insert an inspection row linked to a GRN via the polymorphic columns.
     */
    private function makeInspection(int $grnId, int $productId, string $status): void
    {
        DB::table('inspections')->insert([
            'inspection_number' => 'QC-' . uniqid(),
            'stage'             => 'incoming',
            'status'            => $status,
            'product_id'        => $productId,
            'entity_type'       => 'grn',
            'entity_id'         => $grnId,
            'batch_quantity'    => 100,
            'sample_size'       => 10,
            'accept_count'      => 0,
            'reject_count'      => 0,
            'defect_count'      => 0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // P3.3 — Core regression: open inspections must not dilute pass rate
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Fixture: 8 passed + 1 failed + 1 draft = 10 total rows.
     *
     * Buggy behaviour  : 8 / 10 = 80.00%   (denominator = all rows)
     * Correct behaviour: 8 /  9 = 88.89%   (denominator = terminal only)
     *
     * One GRN per inspection keeps the join simple (one inspection per GRN
     * is the natural real-world case for incoming QC).
     */
    public function test_pass_rate_excludes_non_terminal_inspections_from_denominator(): void
    {
        $productId = $this->makeProduct();
        $poId      = $this->makePo();

        // 8 passed inspections (each on its own GRN).
        for ($i = 0; $i < 8; $i++) {
            $grnId = $this->makeGrn($poId);
            $this->makeInspection($grnId, $productId, 'passed');
        }

        // 1 failed inspection (terminal — counts in denominator but not numerator).
        $grnFailed = $this->makeGrn($poId);
        $this->makeInspection($grnFailed, $productId, 'failed');

        // 1 draft inspection (non-terminal — must NOT count in denominator).
        $grnDraft = $this->makeGrn($poId);
        $this->makeInspection($grnDraft, $productId, 'draft');

        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        // Terminal rows: 8 passed + 1 failed = 9.
        // Pass rate: 8 / 9 = 88.888... → rounded to 88.89.
        $actualRate = (float) $snapshot->quality_pass_rate;

        $this->assertEqualsWithDelta(
            88.89,
            $actualRate,
            0.01,
            "Pass rate should be 8/9 ≈ 88.89% (terminal-only denominator), got {$actualRate}%"
        );

        // Explicitly confirm it is NOT the buggy 80.00% (8/10).
        $this->assertNotEqualsWithDelta(
            80.0,
            $actualRate,
            0.1,
            'Pass rate must NOT be 80% (the buggy all-rows denominator)'
        );
    }

    /**
     * Verify that in_progress (not just draft) also does not dilute the score.
     *
     * Fixture: 8 passed + 1 failed + 1 in_progress = 10 total.
     * Fixed:   8/9 = 88.89%.
     */
    public function test_in_progress_inspection_excluded_from_denominator(): void
    {
        $productId = $this->makeProduct();
        $poId      = $this->makePo();

        for ($i = 0; $i < 8; $i++) {
            $grnId = $this->makeGrn($poId);
            $this->makeInspection($grnId, $productId, 'passed');
        }

        $grnFailed = $this->makeGrn($poId);
        $this->makeInspection($grnFailed, $productId, 'failed');

        $grnInProgress = $this->makeGrn($poId);
        $this->makeInspection($grnInProgress, $productId, 'in_progress');

        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        $actualRate = (float) $snapshot->quality_pass_rate;

        $this->assertEqualsWithDelta(
            88.89,
            $actualRate,
            0.01,
            "in_progress inspection must not dilute score; expected 88.89%, got {$actualRate}%"
        );
    }

    /**
     * Verify the baseline: all inspections passed → 100%.
     */
    public function test_all_passed_gives_100_percent(): void
    {
        $productId = $this->makeProduct();
        $poId      = $this->makePo();

        for ($i = 0; $i < 5; $i++) {
            $grnId = $this->makeGrn($poId);
            $this->makeInspection($grnId, $productId, 'passed');
        }

        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        $this->assertEqualsWithDelta(
            100.0,
            (float) $snapshot->quality_pass_rate,
            0.01,
            'All passed inspections should yield 100% pass rate'
        );
    }

    /**
     * Verify that when all inspections are non-terminal (draft/in_progress),
     * the pass rate falls back rather than producing a divide-by-zero.
     * In this case the service falls back to GRN status.
     */
    public function test_only_non_terminal_inspections_does_not_divide_by_zero(): void
    {
        $productId = $this->makeProduct();
        $poId      = $this->makePo();

        // Only draft inspections — no terminal ones.
        $grnId = $this->makeGrn($poId);
        $this->makeInspection($grnId, $productId, 'draft');

        // Should not throw; passes null or falls back to GRN status.
        $snapshot = $this->service->compute($this->vendor, $this->year, $this->month);

        // Pass rate must be null (no terminal QC data, GRN fallback gives null
        // because the GRN status is 'pending_qc' not 'accepted').
        $this->assertNull(
            $snapshot->quality_pass_rate,
            'Pass rate must be null when no terminal inspections exist and GRN is pending_qc'
        );
    }
}
