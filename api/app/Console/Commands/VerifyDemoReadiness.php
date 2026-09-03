<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
        {--no-warn : Hide advisory WARN checks and report only FAIL-gating ones}';

    protected $description = 'Read-only demo-readiness gate: provenance, money, and seed surfaces. Never writes.';

    public function handle(): int
    {
        $failChecks = [
            'demo_actors'        => $this->checkDemoActors(),
            'orphan_invoices'    => $this->checkOrphanInvoices(),
            'fabricated_money'   => $this->checkFabricatedInvoiceStatuses(),
            'delivery_to_invoice'=> $this->checkDeliveryInvoiceProvenance(),
            'failed_jobs'        => $this->checkFailedJobs(),
            'accounting_periods' => $this->checkAccountingPeriods(),
            'leave_balances'     => $this->checkLeaveBalances(),
            'coc_evidence'       => $this->checkCocEvidence(),
            'hero_trace'         => $this->checkHeroTrace(),
        ];

        $warnChecks = $this->option('no-warn') ? [] : [
            'approval_inbox' => $this->checkApprovalInbox(),
            'stock_ledger'   => $this->checkStockLedger(),
            'chain1_tail'    => $this->checkNonZero('collections', 'Order-to-Cash tail (collections)'),
            'chain2_tail'    => $this->checkNonZero('bill_payments', 'Procure-to-Pay tail (bill_payments)'),
            'chain_runs'     => $this->checkChainRuns(),
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

        $this->info("demo:verify PASSED — 0 critical failures".($warnings > 0 ? " ({$warnings} advisory WARN lines; see above)" : '').'.');
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
                    ->orWhereRaw('i.sample_size > 0 AND m.sampled_units < i.sample_size');
            })
            ->limit(3)
            ->pluck('i.inspection_number');

        if ($bad->isEmpty()) {
            return ['ok' => true, 'message' => 'Every passed outgoing inspection has CoC-grade measurement evidence.'];
        }

        return ['ok' => false, 'message' => 'CoC would fail on passed outgoing inspection(s): '.$bad->implode(', ').' — measure every declared sample with resolved passing rows.'];
    }

    /**
     * The flagship traceability search resolves against a batch-numbered work
     * order whose outgoing QC inspection carries CoC-grade evidence. A fresh
     * canonical seed only reaches that state after the queue drains the MRP
     * outbox AND GoldenPathDemoSeeder is re-run (batch numbers are stamped on
     * the WOs that drain created). Fail loudly when the hero record is missing
     * so a stale or half-built demo DB cannot pass the gate.
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
            ->whereNotNull('i.entity_id')
            ->whereExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('work_orders as w')
                    ->whereColumn('w.id', 'i.entity_id');
            })
            ->count();

        if ($batchWos >= 1 && $heroQc >= 1) {
            return ['ok' => true, 'message' => "Hero trace present: {$batchWos} batch-numbered WO(s), {$heroQc} entity-linked passed outgoing QC."];
        }

        return ['ok' => false, 'message' => 'No hero trace record — a fresh seed needs the MRP outbox drained (start the queue) and GoldenPathDemoSeeder re-run so a batch-numbered WO links to a passed outgoing QC. The flagship traceability search and CoC button depend on it.'];
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
