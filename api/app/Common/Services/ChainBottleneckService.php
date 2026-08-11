<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Series C — Task C5. Chain Bottleneck Detection.
 *
 * Each detector returns rows of entities stuck at the same chain step
 * longer than the configured threshold. Thresholds and audience targeting
 * live in `config/chain.php`.
 *
 * Why use direct DB queries (not Eloquent): the alert engine already
 * scans many entities every 15 minutes; bottleneck checks run hourly
 * against potentially-large tables. A `DB::table()` query with selective
 * columns avoids N+1 hydration overhead and keeps the scheduled command
 * cheap. Hash IDs are computed via the global helper for the API output
 * but not stored — bottleneck rows are transient.
 */
class ChainBottleneckService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Run every detector. Returns a map of detector key -> list of rows.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function detectAll(): array
    {
        $cfg = $this->settings->get('dashboard.chain_bottlenecks', []);
        if (! is_array($cfg) || $cfg === []) {
            return [];
        }
        // Expose the live policy for legacy consumers/tests without making
        // config files the source of truth.
        config()->set('chain.bottlenecks', $cfg);

        $out = [];
        foreach (array_keys($cfg) as $key) {
            $out[$key] = $this->detect($key);
        }

        return $out;
    }

    /**
     * Return the health of the durable cross-module automation ledger.
     *
     * Business bottlenecks and infrastructure bottlenecks are different
     * failure modes, but operators need to see them together. This summary
     * deliberately reports queue lifecycle rows rather than only the latest
     * business status: a published outbox message can still have a listener
     * that is retrying or dead-lettered.
     *
     * @return array<string, mixed>
     */
    public function automationSummary(): array
    {
        $outbox = $this->outboxAutomationSummary();
        $listeners = $this->listenerAutomationSummary();
        $supplierDispatch = $this->supplierDispatchAutomationSummary();
        $failedJobs = $this->failedJobAutomationSummary();

        $status = 'healthy';
        if (! $outbox['available'] || ! $listeners['available'] || ! $supplierDispatch['available'] || ! $failedJobs['available']) {
            $status = 'unavailable';
        } elseif (
            $outbox['failed'] > 0
            || $outbox['stale_pending'] > 0
            || $outbox['stale_processing'] > 0
            || $listeners['failed'] > 0
            || $listeners['retrying'] > 0
            || $listeners['stale_processing'] > 0
            || ($listeners['outcomes']['manual_required'] ?? 0) > 0
            || ($listeners['outcomes']['failed'] ?? 0) > 0
            || $supplierDispatch['failed'] > 0
            || $supplierDispatch['manual_required'] > 0
            || $supplierDispatch['portal_available'] > 0
            || $supplierDispatch['stale_pending'] > 0
            || $failedJobs['total'] > 0
        ) {
            $status = 'attention';
        }

        return [
            'status' => $status,
            'outbox' => $outbox,
            'listeners' => $listeners,
            'supplier_dispatch' => $supplierDispatch,
            'failed_jobs' => $failedJobs,
        ];
    }

    /**
     * Run a single detector. Unknown keys return [].
     *
     * @return array<int, array<string, mixed>>
     */
    public function detect(string $key): array
    {
        $all = $this->settings->get('dashboard.chain_bottlenecks', []);
        $cfg = is_array($all) ? ($all[$key] ?? null) : null;
        if (! is_array($cfg)) {
            return [];
        }

        if (! isset($cfg['hours'], $cfg['audience'], $cfg['label'])) {
            return [];
        }
        $hours = (int) $cfg['hours'];
        $audience = (string) $cfg['audience'];
        $label = (string) $cfg['label'];
        $cutoff = Carbon::now()->subHours($hours);

        return match ($key) {
            'so_at_mrp_planned' => $this->soAtMrpPlanned($cutoff, $key, $label, $audience),
            'wo_confirmed_unstarted' => $this->woConfirmedUnstarted($cutoff, $key, $label, $audience),
            'inspection_outgoing_pending' => $this->inspectionOutgoingPending($cutoff, $key, $label, $audience),
            'delivery_scheduled_overdue' => $this->deliveryScheduledOverdue($cutoff, $key, $label, $audience),
            'invoice_draft_overdue' => $this->invoiceDraftOverdue($cutoff, $key, $label, $audience),
            'pr_pending_overdue' => $this->prPendingOverdue($cutoff, $key, $label, $audience),
            'bill_unpaid_overdue' => $this->billUnpaidOverdue($cutoff, $key, $label, $audience),
            'grn_accepted_without_bill' => $this->grnAcceptedWithoutBill($cutoff, $key, $label, $audience),
            'bill_three_way_manual_review' => $this->billThreeWayManualReview($cutoff, $key, $label, $audience),
            'delivery_confirmed_without_invoice' => $this->deliveryConfirmedWithoutInvoice($cutoff, $key, $label, $audience),
            'production_output_without_receipt' => $this->productionOutputWithoutReceipt($cutoff, $key, $label, $audience),
            'inventory_movement_without_gl' => $this->inventoryMovementWithoutGl($cutoff, $key, $label, $audience),
            'grn_without_incoming_qc' => $this->grnWithoutIncomingQc($cutoff, $key, $label, $audience),
            'return_without_inspection' => $this->returnWithoutInspection($cutoff, $key, $label, $audience),
            'complaint_without_ncr' => $this->complaintWithoutNcr($cutoff, $key, $label, $audience),
            'payroll_gl_without_journal' => $this->payrollGlWithoutJournal($cutoff, $key, $label, $audience),
            default => [],
        };
    }

    // ─── Detectors ─────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function soAtMrpPlanned(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('sales_orders')
            ->select(['id', 'so_number', 'status', 'updated_at'])
            ->where('status', 'confirmed')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'sales_order', 'so_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function woConfirmedUnstarted(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('work_orders')
            ->select(['id', 'wo_number', 'status', 'updated_at'])
            ->where('status', 'confirmed')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'work_order', 'wo_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function inspectionOutgoingPending(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('inspections')
            ->select(['id', 'inspection_number', 'status', 'created_at as updated_at'])
            ->where('stage', 'outgoing')
            ->whereIn('status', ['draft', 'in_progress'])
            ->where('created_at', '<=', $cutoff)
            ->orderBy('created_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'inspection', 'inspection_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function deliveryScheduledOverdue(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('deliveries')
            ->select(['id', 'delivery_number', 'status', 'updated_at'])
            ->where('status', 'scheduled')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'delivery', 'delivery_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function invoiceDraftOverdue(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('invoices')
            ->select(['id', 'invoice_number', 'status', 'updated_at'])
            ->where('status', 'draft')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'invoice', 'invoice_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function prPendingOverdue(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('purchase_requests')
            ->select(['id', 'pr_number', 'status', 'updated_at'])
            ->where('status', 'pending')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'purchase_request', 'pr_number', $key, $label, $audience);
    }

    /** @return array<int, array<string, mixed>> */
    private function billUnpaidOverdue(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        // For bills the "stuck since" reference is due_date, not updated_at —
        // the bill is overdue when due_date is more than $hours in the past.
        $rows = DB::table('bills')
            ->select(['id', 'bill_number', 'status', 'due_date as updated_at'])
            ->where('status', 'unpaid')
            ->where('due_date', '<=', $cutoff->toDateString())
            ->orderBy('due_date')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'bill', 'bill_number', $key, $label, $audience);
    }

    /**
     * An accepted GRN is the source of truth for the auto-bill handoff. If
     * there is no non-cancelled linked bill after the configured SLA, the
     * listener may have failed or the link may have been lost in legacy data.
     * Surface the GRN itself so Finance has a deterministic recovery target.
     *
     * @return array<int, array<string, mixed>>
     */
    private function grnAcceptedWithoutBill(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('goods_receipt_notes as grn')
            ->select([
                'grn.id',
                'grn.grn_number',
                'grn.status',
                'grn.accepted_at as updated_at',
            ])
            ->where('grn.status', 'accepted')
            ->whereNotNull('grn.accepted_at')
            ->where('grn.accepted_at', '<=', $cutoff)
            ->whereNotExists(function ($query): void {
                $query
                    ->select(DB::raw('1'))
                    ->from('bills')
                    ->whereColumn('bills.goods_receipt_note_id', 'grn.id')
                    ->where('bills.status', '<>', 'cancelled');
            })
            ->orderBy('grn.accepted_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'grn', 'grn_number', $key, $label, $audience);
    }

    /**
     * A blocked 3-way match is already persisted on the draft bill. Keep it
     * out of ordinary draft/overdue counts and give the reviewer a direct
     * document-level bottleneck instead. An approved override is deliberately
     * excluded because it is a completed human decision.
     *
     * @return array<int, array<string, mixed>>
     */
    private function billThreeWayManualReview(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('bills')
            ->select(['id', 'bill_number', 'status', 'updated_at'])
            ->where('status', 'draft')
            ->where('has_variances', true)
            ->where('three_way_overridden', false)
            ->whereJsonContains('three_way_match_snapshot->overall_status', 'blocked')
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'bill', 'bill_number', $key, $label, $audience);
    }

    /**
     * A confirmed delivery is the source-of-truth trigger for the customer
     * invoice. Surface it when the invoice handoff has not produced a link,
     * including legacy rows that predate the explicit handoff state.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deliveryConfirmedWithoutInvoice(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('deliveries')
            ->select(['id', 'delivery_number', 'status', 'confirmed_at as updated_at'])
            ->where('status', 'confirmed')
            ->whereNull('invoice_id')
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '<=', $cutoff)
            ->orderBy('confirmed_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'delivery', 'delivery_number', $key, $label, $audience);
    }

    /**
     * A good production output is a committed shop-floor fact, but it is not
     * inventory-complete until its exact finished-goods receipt exists. Group
     * by work order for a stable deep link while using the earliest pending
     * output timestamp as the SLA clock.
     *
     * @return array<int, array<string, mixed>>
     */
    private function productionOutputWithoutReceipt(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('work_order_outputs as output')
            ->join('work_orders as wo', 'wo.id', '=', 'output.work_order_id')
            ->select([
                'wo.id',
                'wo.wo_number',
                DB::raw("'inventory_receipt_pending' as status"),
                DB::raw('MIN(output.production_receipt_handoff_at) as updated_at'),
            ])
            ->where('output.good_count', '>', 0)
            ->whereNotNull('output.production_receipt_handoff_at')
            ->where('output.production_receipt_handoff_at', '<=', $cutoff)
            ->where(function ($query): void {
                $query
                    ->whereIn('output.production_receipt_handoff_status', ['manual_required', 'not_started'])
                    ->orWhere(function ($generated): void {
                        $generated
                            ->where('output.production_receipt_handoff_status', 'generated')
                            ->whereNull('output.production_receipt_movement_id')
                            ->whereNotExists(function ($movement): void {
                                $movement
                                    ->select(DB::raw('1'))
                                    ->from('stock_movements')
                                    ->where('stock_movements.movement_type', 'production_receipt')
                                    ->where('stock_movements.reference_type', 'work_order_output')
                                    ->whereColumn('stock_movements.reference_id', 'output.id');
                            });
                    });
            })
            ->groupBy('wo.id', 'wo.wo_number')
            ->orderByRaw('MIN(output.production_receipt_handoff_at)')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'work_order', 'wo_number', $key, $label, $audience);
    }

    /**
     * A value-changing stock movement is not accounting-complete until it has
     * an exact journal-entry link. The movement itself is the recovery target;
     * its document label is built in PHP to keep the query portable across
     * PostgreSQL, MySQL, and SQLite test databases.
     *
     * @return array<int, array<string, mixed>>
     */
    private function inventoryMovementWithoutGl(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('stock_movements')
            ->select(['id', 'movement_type', 'reference_type', 'gl_handoff_status as status', 'gl_handoff_at as updated_at'])
            ->where('gl_handoff_status', 'manual_required')
            ->whereNotNull('gl_handoff_at')
            ->where('gl_handoff_at', '<=', $cutoff)
            ->orderBy('gl_handoff_at')
            ->limit($this->resultLimit())
            ->get();

        foreach ($rows as $row) {
            $row->doc_number = sprintf(
                'MOV-%d%s',
                (int) $row->id,
                $row->reference_type ? ' · '.$row->reference_type : '',
            );
        }

        return $this->mapRows($rows, 'stock_movement', 'doc_number', $key, $label, $audience);
    }

    /** Surface pending GRNs whose incoming Quality inspection never materialized. */
    private function grnWithoutIncomingQc(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('goods_receipt_notes as grn')
            ->select([
                'grn.id',
                'grn.grn_number',
                'grn.incoming_qc_handoff_status as status',
                'grn.incoming_qc_handoff_at as updated_at',
            ])
            ->where('grn.status', 'pending_qc')
            ->whereIn('grn.incoming_qc_handoff_status', ['manual_required', 'not_started'])
            ->whereNotNull('grn.incoming_qc_handoff_at')
            ->where('grn.incoming_qc_handoff_at', '<=', $cutoff)
            ->whereExists(function ($query): void {
                $query
                    ->select(DB::raw('1'))
                    ->from('grn_items as line')
                    ->whereColumn('line.goods_receipt_note_id', 'grn.id')
                    ->whereNotNull('line.item_id');
            })
            ->whereNotExists(function ($query): void {
                $query
                    ->select(DB::raw('1'))
                    ->from('inspections')
                    ->where('inspections.stage', 'incoming')
                    ->where('inspections.entity_type', 'grn')
                    ->whereColumn('inspections.entity_id', 'grn.id');
            })
            ->orderBy('grn.incoming_qc_handoff_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'grn', 'grn_number', $key, $label, $audience);
    }

    /**
     * An RMA with product-linked lines is not disposition-ready until the
     * Return Management → Quality handoff has staged all required inspections.
     * Include legacy inspected rows migrated to manual_required as well as new
     * received rows that are waiting for operator recovery.
     *
     * @return array<int, array<string, mixed>>
     */
    private function returnWithoutInspection(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('return_requests as rma')
            ->select([
                'rma.id',
                'rma.rma_number',
                'rma.status',
                'rma.inspection_handoff_at as updated_at',
            ])
            ->where('rma.inspection_handoff_status', 'manual_required')
            ->whereNotNull('rma.inspection_handoff_at')
            ->where('rma.inspection_handoff_at', '<=', $cutoff)
            ->whereExists(function ($query): void {
                $query
                    ->select(DB::raw('1'))
                    ->from('return_request_items as item')
                    ->whereColumn('item.return_request_id', 'rma.id')
                    ->whereNotNull('item.product_id');
            })
            ->orderBy('rma.inspection_handoff_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'return_request', 'rma_number', $key, $label, $audience);
    }

    /** Surface complaints whose Quality NCR handoff is still manual. */
    private function complaintWithoutNcr(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('customer_complaints')
            ->select(['id', 'complaint_number', 'status', 'ncr_handoff_at as updated_at'])
            ->where('ncr_handoff_status', 'manual_required')
            ->whereNull('ncr_id')
            ->whereNotNull('ncr_handoff_at')
            ->where('ncr_handoff_at', '<=', $cutoff)
            ->orderBy('ncr_handoff_at')
            ->limit($this->resultLimit())
            ->get();

        return $this->mapRows($rows, 'customer_complaint', 'complaint_number', $key, $label, $audience);
    }

    /** Surface finalized payroll periods whose journal-entry handoff needs recovery. */
    private function payrollGlWithoutJournal(Carbon $cutoff, string $key, string $label, string $audience): array
    {
        $rows = DB::table('payroll_periods')
            ->select([
                'id',
                'status',
                'gl_handoff_status as handoff_status',
                'gl_handoff_at as updated_at',
            ])
            ->whereIn('status', ['finalized', 'disbursed'])
            ->whereNull('journal_entry_id')
            ->whereIn('gl_handoff_status', ['not_started', 'pending', 'manual_required'])
            ->whereNotNull('gl_handoff_at')
            ->where('gl_handoff_at', '<=', $cutoff)
            ->orderBy('gl_handoff_at')
            ->limit($this->resultLimit())
            ->get();

        foreach ($rows as $row) {
            $row->doc_number = 'PAY-'.$row->id;
            $row->status = $row->handoff_status;
        }

        return $this->mapRows($rows, 'payroll_period', 'doc_number', $key, $label, $audience);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    private function resultLimit(): int
    {
        return $this->settings->requiredInt('dashboard.chain_bottlenecks.result_limit', 1, 500);
    }

    /** @return array<string, mixed> */
    private function outboxAutomationSummary(): array
    {
        $summary = [
            'available' => false,
            'total' => 0,
            'pending' => 0,
            'processing' => 0,
            'published' => 0,
            'failed' => 0,
            'stale_pending' => 0,
            'stale_processing' => 0,
            'oldest_pending_at' => null,
            'oldest_failure_at' => null,
        ];

        try {
            if (! Schema::hasTable('event_outbox')) {
                return $summary;
            }

            $counts = $this->statusCounts('event_outbox');
            foreach (['pending', 'processing', 'published', 'failed'] as $status) {
                $summary[$status] = $counts[$status] ?? 0;
            }
            $summary['total'] = array_sum($counts);
            $summary['stale_pending'] = DB::table('event_outbox')
                ->where('status', 'pending')
                ->where('available_at', '<=', now()->subMinutes(10))
                ->count();
            $summary['stale_processing'] = DB::table('event_outbox')
                ->where('status', 'processing')
                ->where(function ($query): void {
                    $query
                        ->where('locked_at', '<=', now()->subMinutes(10))
                        ->orWhereNull('locked_at');
                })
                ->count();
            $summary['oldest_pending_at'] = $this->oldestTimestamp(
                'event_outbox',
                'available_at',
                ['pending'],
            );
            $summary['oldest_failure_at'] = $this->oldestTimestamp(
                'event_outbox',
                'updated_at',
                ['failed'],
            );
            $summary['available'] = true;
        } catch (Throwable) {
            // The dashboard must remain usable during a rolling migration or
            // a temporary metadata-table outage. `unavailable` is surfaced to
            // operators by automationSummary() instead of turning this API
            // into another outage.
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    private function listenerAutomationSummary(): array
    {
        $summary = [
            'available' => false,
            'total' => 0,
            'processing' => 0,
            'retrying' => 0,
            'completed' => 0,
            'failed' => 0,
            'stale_processing' => 0,
            'oldest_active_at' => null,
            'oldest_failure_at' => null,
            'outcomes' => [
                'available' => false,
                'total' => 0,
                'completed' => 0,
                'skipped' => 0,
                'manual_required' => 0,
                'failed' => 0,
                'unclassified' => 0,
            ],
        ];

        try {
            if (! Schema::hasTable('chain_listener_runs')) {
                return $summary;
            }

            $counts = $this->statusCounts('chain_listener_runs');
            foreach (['processing', 'retrying', 'completed', 'failed'] as $status) {
                $summary[$status] = $counts[$status] ?? 0;
            }
            $summary['total'] = array_sum($counts);
            $summary['stale_processing'] = DB::table('chain_listener_runs')
                ->where('status', 'processing')
                ->where(function ($query): void {
                    $query
                        ->where('last_attempt_at', '<=', now()->subMinutes(10))
                        ->orWhereNull('last_attempt_at');
                })
                ->count();
            $summary['oldest_active_at'] = $this->oldestTimestamp(
                'chain_listener_runs',
                'last_attempt_at',
                ['processing', 'retrying'],
            );
            $summary['oldest_failure_at'] = $this->oldestTimestamp(
                'chain_listener_runs',
                'failed_at',
                ['failed'],
            );
            if (Schema::hasColumn('chain_listener_runs', 'outcome_status')) {
                $outcomeCounts = $this->statusCounts('chain_listener_runs', 'outcome_status');
                foreach (['completed', 'skipped', 'manual_required', 'failed'] as $outcome) {
                    $summary['outcomes'][$outcome] = $outcomeCounts[$outcome] ?? 0;
                }
                $knownOutcomes = ['completed', 'skipped', 'manual_required', 'failed'];
                $summary['outcomes']['unclassified'] = DB::table('chain_listener_runs')
                    ->where(function ($query) use ($knownOutcomes): void {
                        $query
                            ->whereNull('outcome_status')
                            ->orWhereNotIn('outcome_status', $knownOutcomes);
                    })
                    ->count();
                // statusCounts() includes the NULL group for in-flight or
                // legacy rows. Count the table directly so those rows are not
                // included once in the grouped sum and again in unclassified.
                $summary['outcomes']['total'] = DB::table('chain_listener_runs')->count();
                $summary['outcomes']['available'] = true;
            }
            $summary['available'] = true;
        } catch (Throwable) {
            // See outboxAutomationSummary().
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    private function supplierDispatchAutomationSummary(): array
    {
        $summary = [
            'available' => false,
            'total' => 0,
            'pending' => 0,
            'portal_available' => 0,
            'manual_required' => 0,
            'confirmed' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'stale_pending' => 0,
            'oldest_attention_at' => null,
        ];

        try {
            if (! Schema::hasTable('supplier_order_dispatches')) {
                return $summary;
            }

            $counts = $this->statusCounts('supplier_order_dispatches');
            foreach (['pending', 'portal_available', 'manual_required', 'confirmed', 'failed', 'cancelled'] as $status) {
                $summary[$status] = $counts[$status] ?? 0;
            }
            $summary['total'] = array_sum($counts);
            $summary['stale_pending'] = DB::table('supplier_order_dispatches')
                ->where('status', 'pending')
                ->where(function ($query): void {
                    $query
                        ->where('last_attempt_at', '<=', now()->subMinutes(10))
                        ->orWhereNull('last_attempt_at');
                })
                ->count();
            $summary['oldest_attention_at'] = $this->oldestTimestamp(
                'supplier_order_dispatches',
                'updated_at',
                ['pending', 'portal_available', 'manual_required', 'failed'],
            );
            $summary['available'] = true;
        } catch (Throwable) {
            // Keep the dashboard available during a rolling migration or
            // temporary dispatch-table outage.
        }

        return $summary;
    }

    /** @return array{available: bool, total: int, oldest_at: string|null} */
    private function failedJobAutomationSummary(): array
    {
        $summary = [
            'available' => false,
            'total' => 0,
            'oldest_at' => null,
        ];

        try {
            if (! Schema::hasTable('failed_jobs')) {
                return $summary;
            }

            $summary['total'] = DB::table('failed_jobs')->count();
            $summary['oldest_at'] = $this->formatTimestamp(
                DB::table('failed_jobs')->min('failed_at'),
            );
            $summary['available'] = true;
        } catch (Throwable) {
            // See outboxAutomationSummary(). A missing or temporarily
            // unavailable queue table must be visible as unavailable, not as
            // a false healthy zero.
        }

        return $summary;
    }

    /** @return array<string, int> */
    private function statusCounts(string $table, string $column = 'status'): array
    {
        $rows = DB::table($table)
            ->select([$column, DB::raw('COUNT(*) as aggregate')])
            ->groupBy($column)
            ->pluck('aggregate', $column);

        $counts = [];
        foreach ($rows as $status => $count) {
            $counts[(string) $status] = (int) $count;
        }

        return $counts;
    }

    /** @param list<string> $statuses */
    private function oldestTimestamp(string $table, string $column, array $statuses): ?string
    {
        $raw = DB::table($table)
            ->whereIn('status', $statuses)
            ->whereNotNull($column)
            ->min($column);

        return $this->formatTimestamp($raw);
    }

    private function formatTimestamp(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse((string) $raw)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Common row-mapper. Adds hash_id, hours_stuck, key/label/audience.
     *
     * @param  iterable<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapRows(
        iterable $rows,
        string $entityType,
        string $docNumberField,
        string $key,
        string $label,
        string $audience,
    ): array {
        $out = [];
        $now = Carbon::now();
        foreach ($rows as $row) {
            $stuckSince = $this->parseTimestamp($row->updated_at ?? null);
            $hoursStuck = $stuckSince ? $stuckSince->diffInHours($now) : null;

            $out[] = [
                'key' => $key,
                'label' => $label,
                'audience' => $audience,
                'entity_type' => $entityType,
                'entity_id' => app('hashids')->encode((int) $row->id),
                'doc_number' => (string) ($row->{$docNumberField} ?? ''),
                'status' => (string) ($row->status ?? ''),
                'stuck_since' => $stuckSince?->toIso8601String(),
                'hours_stuck' => $hoursStuck,
            ];
        }

        return $out;
    }

    private function parseTimestamp(mixed $raw): ?Carbon
    {
        if ($raw === null) {
            return null;
        }
        try {
            return $raw instanceof Carbon ? $raw : Carbon::parse((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }
}
