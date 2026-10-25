<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Models\DashboardWidget;
use App\Modules\Dashboard\Models\KpiDefinition;
use App\Modules\Dashboard\Models\KpiSnapshot;
use App\Modules\Dashboard\Services\DashboardWidgetDataService;
use App\Modules\Dashboard\Services\WidgetAnalyticsService;
use App\Modules\DSS\Models\DssRunLog;
use App\Modules\DSS\Services\DetectorRegistry;
use App\Modules\DSS\Services\DssParameterService;
use App\Modules\Forecasting\Services\ForecastingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only demo-readiness gate (Track C — demo hardening).
 *
 * Reports the surfaces a free-click panel will open (docs/superpowers/specs/
 * 2026-08-11-demo-hardening-design.md §1.1/§1.2):
 *
 *   FAIL (exit-gating) — broken or missing data a panelist would read as a
 *     defect: orphan invoices, paid/partial invoices with no collection
 *     record, a delivery that never produced an invoice, failed jobs, no
 *     accounting period, no leave balances, missing demo actors.
 *   WARN (advisory)   — surfaces worth having for the narrative: pending
 *     approval inbox, stock movement history, chain tails, chain observability.
 *
 * The command only ever SELECTs. A test asserts the key-table row counts are
 * byte-identical before and after an invocation.
 */
class VerifyDemoReadiness extends Command
{
    protected $signature = 'demo:verify
        {--no-warn : Hide advisory WARN checks and report only FAIL-gating ones}
        {--analytics : Check dashboard payloads, KPI history, forecasts, and DSS runs}';

    protected $description = 'Read-only demo-readiness gate: provenance, money, and seed surfaces. Never writes.';

    public function handle(): int
    {
        $failChecks = [
            'demo_actors' => $this->checkDemoActors(),
            'orphan_invoices' => $this->checkOrphanInvoices(),
            'fabricated_money' => $this->checkFabricatedInvoiceStatuses(),
            'delivery_to_invoice' => $this->checkDeliveryInvoiceProvenance(),
            'failed_jobs' => $this->checkFailedJobs(),
            'accounting_periods' => $this->checkAccountingPeriods(),
            'leave_balances' => $this->checkLeaveBalances(),
            'coc_evidence' => $this->checkCocEvidence(),
            'hero_trace' => $this->checkHeroTrace(),
        ];

        if ($this->option('analytics')) {
            $failChecks['analytics_process_history'] = $this->checkAnalyticsProcessHistory();
            $failChecks['analytics_supplier_history'] = $this->checkAnalyticsSupplierHistory();
            $failChecks['analytics_widgets'] = $this->checkAnalyticsWidgets();
            $failChecks['kpi_trends'] = $this->checkKpiTrends();
            $failChecks['forecast_history'] = $this->checkForecastHistory();
            $failChecks['dss_runs'] = $this->checkDssRuns();
        }

        $warnChecks = $this->option('no-warn') ? [] : [
            'approval_inbox' => $this->checkApprovalInbox(),
            'stock_ledger' => $this->checkStockLedger(),
            'chain1_tail' => $this->checkNonZero('collections', 'Order-to-Cash tail (collections)'),
            'chain2_tail' => $this->checkNonZero('bill_payments', 'Procure-to-Pay tail (bill_payments)'),
            'chain_runs' => $this->checkChainRuns(),
        ];

        $rows = [];
        foreach ($failChecks as $name => $r) {
            $rows[] = [$name, $r['ok'] ? '<info>PASS</info>' : '<error>FAIL</error>', $r['message']];
        }
        foreach ($warnChecks as $name => $r) {
            $rows[] = [$name, $r['ok'] ? '<info>PASS</info>' : '<comment>WARN</comment>', $r['message']];
        }

        $this->table(['Check', 'Status', 'Detail'], $rows);

        $failures = collect($failChecks)->reject(fn ($r) => $r['ok'])->count();
        $warnings = collect($warnChecks)->reject(fn ($r) => $r['ok'])->count();

        if ($failures > 0) {
            $this->error("demo:verify FAILED — {$failures} critical check(s). Fix the FAIL lines above, then re-run. (WARN lines are advisory.)");

            return self::FAILURE;
        }

        $this->info('demo:verify PASSED — 0 critical failures'.($warnings > 0 ? " ({$warnings} advisory WARN lines; see above)" : '').'.');

        return self::SUCCESS;
    }

    /* ─── FAIL-gating checks ─────────────────────────────────────────── */

    /** @return array{ok: bool, message: string} */
    private function checkDemoActors(): array
    {
        // The internal admin lives in `users`; B2B portal accounts live in
        // their OWN tables behind separate guards (auth:supplier_portal /
        // auth:customer_portal providers), so each email is checked against
        // the table its login actually authenticates against.
        $checks = [
            ['email' => 'admin@ogami.test',    'table' => 'users'],
            ['email' => 'portal@supp.test',     'table' => 'supplier_portal_users'],
            ['email' => 'portal@cust.test',     'table' => 'customer_portal_users'],
        ];

        $missing = [];
        foreach ($checks as $c) {
            if (! Schema::hasTable($c['table']) || ! DB::table($c['table'])->where('email', $c['email'])->exists()) {
                $missing[] = $c['email'];
            }
        }

        return $missing === []
            ? ['ok' => true, 'message' => 'Demo actors present (admin@ogami.test in users; portal accounts in their portal tables).']
            : ['ok' => false, 'message' => 'Missing demo accounts: '.implode(', ', $missing).'. Re-run GoldenPathDemoSeeder (portal accounts).'];
    }

    /** @return array{ok: bool, message: string} */
    private function checkOrphanInvoices(): array
    {
        if (! Schema::hasTable('invoices')) {
            return ['ok' => true, 'message' => 'No invoices table (Accounting not migrated).'];
        }

        $orphans = DB::table('invoices')
            ->whereNull('delivery_id')
            ->whereNull('sales_order_id')
            ->count();

        return $orphans === 0
            ? ['ok' => true, 'message' => 'Zero orphan invoices (every invoice is linked to a delivery or SO).']
            : ['ok' => false, 'message' => "{$orphans} orphan invoice(s) with no delivery_id and no sales_order_id. Repair via the reviewed user procedure (spec §2.2)."];
    }

    /** @return array{ok: bool, message: string} */
    private function checkFabricatedInvoiceStatuses(): array
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasTable('collections')) {
            return ['ok' => true, 'message' => 'Accounting tables absent — nothing to verify.'];
        }

        $fake = DB::table('invoices as i')
            ->leftJoin('collections as c', 'c.invoice_id', '=', 'i.id')
            ->whereIn('i.status', ['paid', 'partial'])
            ->whereNull('c.id')
            ->count();

        return $fake === 0
            ? ['ok' => true, 'message' => 'Every paid/partial invoice is backed by a collections row.']
            : ['ok' => false, 'message' => "{$fake} invoice(s) claim paid/partial with no collection record. No money movement backs that status."];
    }

    /** @return array{ok: bool, message: string} */
    private function checkDeliveryInvoiceProvenance(): array
    {
        if (! Schema::hasTable('invoices')) {
            return ['ok' => true, 'message' => 'No invoices table (Accounting not migrated).'];
        }

        $linked = DB::table('invoices')->whereNotNull('delivery_id')->count();

        return $linked > 0
            ? ['ok' => true, 'message' => "{$linked} invoice(s) produced from a confirmed delivery (real handoff)."]
            : ['ok' => false, 'message' => 'No invoice is linked to a delivery. Confirm a delivered delivery (delivery proofs required) so the draft invoice is chain-produced.'];
    }

    /** @return array{ok: bool, message: string} */
    private function checkFailedJobs(): array
    {
        $count = DB::table('failed_jobs')->count();

        return $count === 0
            ? ['ok' => true, 'message' => 'No failed jobs.']
            : ['ok' => false, 'message' => "{$count} failed job(s) — a red number if any sysadmin screen is opened. Retry or prune them (user action, after review)."];
    }

    /** @return array{ok: bool, message: string} */
    private function checkAccountingPeriods(): array
    {
        if (! Schema::hasTable('accounting_periods')) {
            return ['ok' => true, 'message' => 'No accounting_periods table.'];
        }

        $open = DB::table('accounting_periods')->where('status', 'open')->count();

        return $open > 0
            ? ['ok' => true, 'message' => "{$open} open accounting period(s) — period locks have something to lock."]
            : ['ok' => false, 'message' => 'No open accounting period. Seed the current year/month as open.'];
    }

    /** @return array{ok: bool, message: string} */
    private function checkLeaveBalances(): array
    {
        if (! Schema::hasTable('employee_leave_balances')) {
            return ['ok' => true, 'message' => 'No employee_leave_balances table.'];
        }

        $balances = DB::table('employee_leave_balances')->count();

        return $balances > 0
            ? ['ok' => true, 'message' => "{$balances} leave balance row(s)."]
            : ['ok' => false, 'message' => 'Zero leave balances — every leave screen reads empty. Seed balances for employees with leave requests.'];
    }

    /**
     * Every passed outgoing inspection must carry enough resolved, passing
     * measurement evidence to issue its Certificate of Conformance. The demo
     * flagships the CoC button on the hero QC record; a passed inspection with
     * zero rows or a short sample makes that button fail (CoCService guard).
     * Vacuous pass when no passed outgoing inspection exists.
     *
     * @return array{ok: bool, message: string}
     */
    private function checkCocEvidence(): array
    {
        if (! Schema::hasTable('inspections') || ! Schema::hasTable('inspection_measurements')) {
            return ['ok' => true, 'message' => 'No inspections tables.'];
        }

        $bad = DB::table('inspections as i')
            ->leftJoinSub(
                DB::table('inspection_measurements')
                    ->select('inspection_id')
                    ->selectRaw('count(*) as total')
                    ->selectRaw('count(*) filter (where is_pass is null) as unresolved')
                    ->selectRaw('count(*) filter (where is_pass = false) as failing')
                    ->selectRaw('count(distinct sample_index) as sampled_units')
                    ->selectRaw("count(*) filter (where item_result is distinct from 'passed' or nullif(btrim(acceptance_criterion), '') is null) as incomplete_checklist_rows")
                    ->groupBy('inspection_id'),
                'm',
                'm.inspection_id',
                '=',
                'i.id'
            )
            ->where('i.stage', 'outgoing')
            ->where('i.status', 'passed')
            ->where(function ($q): void {
                $q->whereNull('m.total')
                    ->orWhere('m.unresolved', '>', 0)
                    ->orWhere('m.failing', '>', 0)
                    ->orWhereRaw('i.sample_size > 0 AND m.sampled_units < i.sample_size')
                    ->orWhere(function ($query): void {
                        $query->where('i.inspection_mode', 'lot_checklist')
                            ->where(function ($checklist): void {
                                $checklist->whereNull('i.inspected_quantity')
                                    ->orWhereColumn('i.inspected_quantity', '<', 'i.sample_size')
                                    ->orWhereNull('i.sample_defect_count')
                                    ->orWhere('i.sample_defect_count', '!=', 0)
                                    ->orWhere('i.critical_defect', true)
                                    ->orWhere('m.incomplete_checklist_rows', '>', 0);
                            });
                    });
            })
            ->limit(3)
            ->pluck('i.inspection_number');

        if ($bad->isEmpty()) {
            return ['ok' => true, 'message' => 'Every passed outgoing inspection has CoC-grade measurement evidence.'];
        }

        return ['ok' => false, 'message' => 'CoC would fail on passed outgoing inspection(s): '.$bad->implode(', ').' — measure every declared sample with resolved passing rows.'];
    }

    /**
     * Require one complete order-to-cash production trace. The same SO line
     * must connect to a batch WO, passed outgoing QC, delivery item, and
     * delivery-linked invoice; unrelated records cannot satisfy the check.
     *
     * @return array{ok: bool, message: string}
     */
    private function checkHeroTrace(): array
    {
        if (! Schema::hasTable('work_orders') || ! Schema::hasTable('inspections')) {
            return ['ok' => true, 'message' => 'No traceability tables.'];
        }

        $totalWos = DB::table('work_orders')->count();
        if ($totalWos < 1) {
            return ['ok' => true, 'message' => 'No work orders; hero trace not applicable.'];
        }

        $batchWos = DB::table('work_orders')->whereNotNull('batch_number')->count();
        $heroQc = DB::table('inspections as i')
            ->where('i.stage', 'outgoing')
            ->where('i.status', 'passed')
            ->where('i.entity_type', 'work_order')
            ->whereNotNull('i.entity_id')
            ->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('work_orders as w')
                    ->whereColumn('w.id', 'i.entity_id');
            })
            ->count();

        $completePaths = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->join('work_orders as w', function ($join): void {
                $join->on('w.sales_order_id', '=', 'so.id')
                    ->on('w.product_id', '=', 'soi.product_id');
            })
            ->join('inspections as i', function ($join): void {
                $join->on('i.entity_id', '=', 'w.id')
                    ->where('i.entity_type', 'work_order')
                    ->where('i.stage', 'outgoing')
                    ->where('i.status', 'passed');
            })
            ->join('delivery_items as di', function ($join): void {
                $join->on('di.sales_order_item_id', '=', 'soi.id')
                    ->on('di.inspection_id', '=', 'i.id');
            })
            ->join('deliveries as d', function ($join): void {
                $join->on('d.id', '=', 'di.delivery_id')
                    ->on('d.sales_order_id', '=', 'so.id')
                    ->whereIn('d.status', ['delivered', 'confirmed']);
            })
            ->join('invoices as inv', function ($join): void {
                $join->on('inv.delivery_id', '=', 'd.id')
                    ->on('inv.sales_order_id', '=', 'so.id');
            })
            ->whereNotNull('w.batch_number')
            ->whereIn('w.status', ['completed', 'closed'])
            ->distinct()
            ->count('so.id');

        if ($batchWos >= 1 && $heroQc >= 1 && $completePaths >= 1) {
            return ['ok' => true, 'message' => "Hero trace present: {$batchWos} batch WO(s), {$heroQc} passed outgoing QC, {$completePaths} complete SO/WO/QC/delivery/invoice path(s)."];
        }

        return ['ok' => false, 'message' => "Incomplete order trace: batch WOs={$batchWos}, linked passed outgoing QC={$heroQc}, complete SO/WO/QC/delivery/invoice paths={$completePaths}. Drain MRP, run GoldenPathDemoSeeder, then AnalyticsDemoSeeder."];
    }

    /** Every analytics demo order must traverse each required source process. */
    private function checkAnalyticsProcessHistory(): array
    {
        $tables = ['sales_orders', 'sales_order_items', 'work_orders', 'material_issue_slips', 'material_issue_slip_items', 'inspections', 'delivery_items', 'deliveries', 'delivery_proofs', 'invoices'];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return ['ok' => false, 'message' => "Required process table {$table} is missing."];
            }
        }

        $complete = DB::table('sales_orders as so')
            ->join('sales_order_items as soi', 'soi.sales_order_id', '=', 'so.id')
            ->join('work_orders as w', function ($join): void {
                $join->on('w.sales_order_id', '=', 'so.id')
                    ->on('w.product_id', '=', 'soi.product_id');
            })
            ->join('material_issue_slips as mis', function ($join): void {
                $join->on('mis.work_order_id', '=', 'w.id')->where('mis.status', 'issued');
            })
            ->join('material_issue_slip_items as mii', 'mii.material_issue_slip_id', '=', 'mis.id')
            ->join('inspections as i', function ($join): void {
                $join->on('i.entity_id', '=', 'w.id')
                    ->where('i.entity_type', 'work_order')
                    ->where('i.stage', 'outgoing')
                    ->where('i.status', 'passed');
            })
            ->join('delivery_items as di', function ($join): void {
                $join->on('di.sales_order_item_id', '=', 'soi.id')
                    ->on('di.inspection_id', '=', 'i.id');
            })
            ->join('deliveries as d', function ($join): void {
                $join->on('d.id', '=', 'di.delivery_id')
                    ->on('d.sales_order_id', '=', 'so.id')
                    ->whereIn('d.status', ['delivered', 'confirmed']);
            })
            ->join('invoices as inv', function ($join): void {
                $join->on('inv.delivery_id', '=', 'd.id')
                    ->on('inv.sales_order_id', '=', 'so.id');
            })
            ->where('so.notes', 'like', 'Demo seed%')
            ->whereIn('w.status', ['completed', 'closed'])
            ->whereNotNull('w.actual_end')
            ->whereNotNull('w.batch_number')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('delivery_proofs as dp')
                    ->whereColumn('dp.delivery_id', 'd.id')
                    ->whereNull('dp.deleted_at');
            })
            ->distinct()
            ->count('so.id');

        return $complete >= 10
            ? ['ok' => true, 'message' => "{$complete} seeded order(s) include work order, issued materials, passed QC, signed delivery, and invoice."]
            : ['ok' => false, 'message' => "Only {$complete} of 10 required seeded orders complete every production-to-cash step, including QC."];
    }

    /** Supplier history must include linked, evidenced incoming QC each month. */
    private function checkAnalyticsSupplierHistory(): array
    {
        foreach (['goods_receipt_notes', 'inspections', 'inspection_measurements'] as $table) {
            if (! Schema::hasTable($table)) {
                return ['ok' => false, 'message' => "Required supplier-quality table {$table} is missing."];
            }
        }

        $receipts = DB::table('goods_receipt_notes as g')
            ->join('inspections as i', function ($join): void {
                $join->on('i.entity_id', '=', 'g.id')
                    ->where('i.entity_type', 'grn')
                    ->where('i.stage', 'incoming')
                    ->where('i.status', 'passed');
            })
            ->join('inspection_measurements as m', function ($join): void {
                $join->on('m.inspection_id', '=', 'i.id')
                    ->where('m.item_result', 'passed')
                    ->whereNotNull('m.acceptance_criterion');
            })
            ->where('g.grn_number', 'like', 'AN-GRN-%')
            ->select('g.id', 'g.received_date')
            ->distinct()
            ->get();
        $months = $receipts->pluck('received_date')
            ->map(static fn ($date): string => substr((string) $date, 0, 7))
            ->unique()
            ->count();

        return $months >= 10
            ? ['ok' => true, 'message' => "{$months} months of supplier receipts have linked passed incoming QC evidence."]
            : ['ok' => false, 'message' => "Only {$months} of 10 required supplier months have linked passed incoming QC evidence."];
    }

    /** Every registered rich widget must resolve its declared graph/table shape. */
    private function checkAnalyticsWidgets(): array
    {
        $user = User::query()->where('email', 'admin@ogami.test')->first();
        $widgets = DashboardWidget::query()->orderBy('key')->get();
        if (! $user || $widgets->isEmpty()) {
            return ['ok' => false, 'message' => 'Admin actor or dashboard widget catalog missing.'];
        }

        $summaries = app(DashboardWidgetDataService::class)
            ->summaries($widgets->pluck('key')->all(), $user);
        $unavailable = [];
        foreach ($summaries as $key => $summary) {
            if (! ($summary['available'] ?? false)) {
                $unavailable[] = $key;
            }
        }

        $analytics = app(WidgetAnalyticsService::class);
        $empty = [];
        foreach ($widgets as $widget) {
            $kind = $widget->render_kind;
            if (! $kind instanceof RenderKind || $kind === RenderKind::Scalar) {
                continue;
            }

            $payload = $analytics->payload($widget->key, $kind, $user);
            $key = $kind->payloadKey();
            $value = $payload[$key] ?? null;
            $hasData = match ($kind) {
                RenderKind::Trend, RenderKind::Breakdown, RenderKind::Table => is_array($value) && $value !== [],
                RenderKind::Gauge => $value !== null,
                RenderKind::Scalar => true,
            };

            if ($analytics->failed($widget->key) || ! $hasData) {
                $empty[] = $widget->key;
            }
        }

        $issues = [];
        if ($unavailable !== []) {
            $issues[] = 'unavailable summaries: '.implode(', ', array_slice($unavailable, 0, 8));
        }
        if ($empty !== []) {
            $issues[] = 'empty or failed rich payloads: '.implode(', ', array_slice($empty, 0, 8));
        }

        return $issues === []
            ? ['ok' => true, 'message' => "All {$widgets->count()} widgets returned available data and every rich widget has a populated payload."]
            : ['ok' => false, 'message' => implode('; ', $issues).'.'];
    }

    /** Each KPI needs enough measured points for its 12-month graph to show a trend. */
    private function checkKpiTrends(): array
    {
        $definitions = KpiDefinition::query()->where('is_active', true)->orderBy('code')->get();
        if ($definitions->isEmpty()) {
            return ['ok' => false, 'message' => 'No active KPI definitions are seeded.'];
        }

        $cutoff = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow(11);
        $short = [];
        foreach ($definitions as $definition) {
            $points = KpiSnapshot::query()
                ->where('definition_id', $definition->id)
                ->where(function ($query) use ($cutoff): void {
                    $query->where('period_year', '>', $cutoff->year)
                        ->orWhere(function ($sameYear) use ($cutoff): void {
                            $sameYear->where('period_year', $cutoff->year)
                                ->where('period_month', '>=', $cutoff->month);
                        });
                })
                ->count();

            if ($points < 10) {
                $short[] = $definition->code.'='.$points;
            }
        }

        return $short === []
            ? ['ok' => true, 'message' => "All {$definitions->count()} KPI trends have at least ten measured points in the trailing 12 months."]
            : ['ok' => false, 'message' => 'KPI trend history under ten points: '.implode(', ', $short).'.'];
    }

    /** Forecast actuals must reconcile to source orders; future estimates must exist. */
    private function checkForecastHistory(): array
    {
        if (! Schema::hasTable('demand_forecasts')) {
            return ['ok' => false, 'message' => 'Demand forecast table is missing.'];
        }

        $currentMonth = CarbonImmutable::now()->startOfMonth();
        $future = DB::table('demand_forecasts')
            ->whereNull('customer_id')
            ->where(function ($query) use ($currentMonth): void {
                $query->where('forecast_year', '>', $currentMonth->year)
                    ->orWhere(function ($sameYear) use ($currentMonth): void {
                        $sameYear->where('forecast_year', $currentMonth->year)
                            ->where('forecast_month', '>=', $currentMonth->month);
                    });
            })
            ->count();
        $historical = DB::table('demand_forecasts')
            ->whereNull('customer_id')
            ->whereNotNull('actual_quantity')
            ->where(function ($query) use ($currentMonth): void {
                $query->where('forecast_year', '<', $currentMonth->year)
                    ->orWhere(function ($sameYear) use ($currentMonth): void {
                        $sameYear->where('forecast_year', $currentMonth->year)
                            ->where('forecast_month', '<', $currentMonth->month);
                    });
            })
            ->get(['product_id', 'forecast_year', 'forecast_month', 'actual_quantity']);

        $mismatches = 0;
        foreach ($historical as $forecast) {
            $period = CarbonImmutable::create((int) $forecast->forecast_year, (int) $forecast->forecast_month, 1);
            $actual = DB::table('sales_order_items as soi')
                ->join('sales_orders as so', 'so.id', '=', 'soi.sales_order_id')
                ->where('soi.product_id', $forecast->product_id)
                ->whereNotIn('so.status', ['cancelled', 'draft'])
                ->whereBetween('so.date', [$period->toDateString(), $period->endOfMonth()->toDateString()])
                ->sum('soi.quantity');

            if (round((float) $actual, 2) !== round((float) $forecast->actual_quantity, 2)) {
                $mismatches++;
            }
        }

        $accuracy = app(ForecastingService::class)->accuracy($currentMonth->year);
        if ($future === 0 || $historical->isEmpty() || $mismatches > 0 || $accuracy['periods_evaluated'] === 0) {
            return ['ok' => false, 'message' => "Future forecasts={$future}, reconciled periods={$historical->count()}, source mismatches={$mismatches}, evaluated accuracy periods={$accuracy['periods_evaluated']}; need future values and source-backed completed periods."];
        }

        return ['ok' => true, 'message' => "{$future} future forecasts; {$historical->count()} completed periods reconcile to sales orders; {$accuracy['periods_evaluated']} periods included in accuracy."];
    }

    /** Enabled detectors must have a successful full scan from the latest demo seed. */
    private function checkDssRuns(): array
    {
        $parameters = app(DssParameterService::class);
        if (! $parameters->engineEnabled()) {
            return ['ok' => false, 'message' => 'DSS engine is disabled; enable it only in the disposable demo database to test detector outputs.'];
        }

        $missing = [];
        $bad = [];
        foreach (app(DetectorRegistry::class)->all() as $id => $_class) {
            if (! $parameters->detectorEnabled($id)) {
                continue;
            }

            $status = DssRunLog::query()->where('detector_id', $id)->latest('id')->value('status');
            if ($status === null) {
                $missing[] = $id;
            } elseif ($status !== 'completed') {
                $bad[] = $id.'='.$status;
            }
        }

        if ($missing !== [] || $bad !== []) {
            $details = [];
            if ($missing !== []) {
                $details[] = 'never run: '.implode(', ', $missing);
            }
            if ($bad !== []) {
                $details[] = 'latest run not complete: '.implode(', ', $bad);
            }

            return ['ok' => false, 'message' => implode('; ', $details).'.'];
        }

        return ['ok' => true, 'message' => 'Every enabled DSS detector has a completed run; detector modes remain unchanged.'];
    }

    /* ─── Advisory (WARN) checks ─────────────────────────────────────── */

    /** @return array{ok: bool, message: string} */
    private function checkApprovalInbox(): array
    {
        $pending = DB::table('approval_records')
            ->where('action', 'pending')
            ->where('is_current', true)
            ->count();

        return $pending > 0
            ? ['ok' => true, 'message' => "{$pending} pending approval record(s) — the approval inbox has live items."]
            : ['ok' => false, 'message' => 'No pending approval records — the approval inbox (a thesis centerpiece) is empty.'];
    }

    /** @return array{ok: bool, message: string} */
    private function checkStockLedger(): array
    {
        $movements = DB::table('stock_movements')->count();

        return $movements > 0
            ? ['ok' => true, 'message' => "{$movements} stock movement(s) — stock cards have history."]
            : ['ok' => false, 'message' => 'Zero stock movements — every stock card is empty.'];
    }

    /** @return array{ok: bool, message: string} */
    private function checkNonZero(string $table, string $label): array
    {
        $count = DB::table($table)->count();

        return $count > 0
            ? ['ok' => true, 'message' => "{$count} row(s) — {$label} is populated."]
            : ['ok' => false, 'message' => "Zero rows — {$label} stops short."];
    }

    /** @return array{ok: bool, message: string} */
    private function checkChainRuns(): array
    {
        $chains = DB::table('chain_step_runs')
            ->select('chain', DB::raw('count(*) as total'))
            ->groupBy('chain')
            ->orderBy('chain')
            ->get()
            ->map(fn ($row) => "{$row->chain}={$row->total}")
            ->implode(', ');

        return $chains !== ''
            ? ['ok' => true, 'message' => "Chain observability: {$chains}."]
            : ['ok' => false, 'message' => 'No chain_step_runs rows — chain views show no executed steps.'];
    }
}
