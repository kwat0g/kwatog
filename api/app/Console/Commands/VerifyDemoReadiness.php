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
        $required = ['admin@ogami.test', 'portal@supp.test', 'portal@cust.test'];
        $missing = [];
        foreach ($required as $email) {
            if (! DB::table('users')->where('email', $email)->exists()) {
                $missing[] = $email;
            }
        }

        return $missing === []
            ? ['ok' => true, 'message' => 'Demo actors present ('.implode(', ', $required).').']
            : ['ok' => false, 'message' => 'Missing demo accounts: '.implode(', ', $missing).'. Re-run DemoAccountSeeder.'];
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

    /* ─── Advisory (WARN) checks ─────────────────────────────────────── */

    /** @return array{ok: bool, message: string} */
    private function checkApprovalInbox(): array
    {
        $pending = DB::table('approval_records')->where('action', 'pending')->count();

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
