<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\CreditNoteService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\HR\Models\Department;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\ShipmentLot;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Golden-path demo seeder — lights up the live-demo showcase screens that the
 * base seeders leave empty (adviser items ADV1/3/7/9/12). Additive and
 * idempotent: every section guards on an existence check, so re-runs are safe.
 *
 *   php artisan db:seed --class=GoldenPathDemoSeeder
 *
 * Fills: work_order batch numbers + material-lot refs, shipment lots, delivery
 * proofs, a disbursed payroll period + proof, a finalized credit note, and
 * FY2026 department budgets (one near-critical). Uses real FK ids fetched at
 * run time — nothing hardcoded.
 */
class GoldenPathDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->section('batch numbers (ADV3)', fn () => $this->seedBatchNumbers());
        $this->section('hero trace linkage (ADV3)', fn () => $this->hardenHeroTrace());
        $this->section('shipment lots (ADV3)', fn () => $this->seedShipmentLots());
        $this->section('delivery proofs (ADV7)', fn () => $this->seedDeliveryProofs());
        $this->section('disbursement proof (ADV1)', fn () => $this->seedDisbursement());
        $this->section('credit note (ADV12)', fn () => $this->seedCreditNote());
        $this->section('budgets (ADV9)', fn () => $this->seedBudgets());

        $this->command?->info('Golden-path demo seed complete.');
    }

    /** Run one section, logging + swallowing failures so the rest still seed. */
    private function section(string $label, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->command?->warn("  [$label] skipped: " . $e->getMessage());
        }
    }

    private function admin(): ?User
    {
        return User::where('email', 'admin@ogami.test')->first() ?? User::first();
    }

    /** ADV3 — stamp a batch number + material-lot trace on every work order. */
    private function seedBatchNumbers(): void
    {
        $wos = WorkOrder::whereNull('batch_number')->orderBy('id')->get();
        if ($wos->isEmpty()) {
            $this->command?->info('  Batch numbers already present.');
            return;
        }

        $n = 0;
        foreach ($wos as $wo) {
            $date = Carbon::parse($wo->actual_start ?? $wo->planned_start ?? now());
            $batch = 'BATCH-' . $date->format('Ymd') . '-' . str_pad((string) ($wo->id), 4, '0', STR_PAD_LEFT);

            $wo->forceFill([
                'batch_number' => $batch,
                'material_lot_references' => [
                    [
                        'item_id'                => null,
                        'item_code'              => 'RESIN-ABS',
                        'item_name'              => 'Resin A (ABS)',
                        'grn_number'             => 'GRN-' . $date->copy()->subDays(5)->format('Ym') . '-0001',
                        'material_lot_number'    => 'MLOT-' . $date->copy()->subDays(5)->format('Ymd') . '-01',
                        'supplier_lot_reference' => 'SL-TW-0234',
                        'quantity_used'          => 150,
                    ],
                    [
                        'item_id'                => null,
                        'item_code'              => 'COL-BLACK',
                        'item_name'              => 'Black Colorant',
                        'grn_number'             => 'GRN-' . $date->copy()->subDays(6)->format('Ym') . '-0002',
                        'material_lot_number'    => 'MLOT-' . $date->copy()->subDays(6)->format('Ymd') . '-02',
                        'supplier_lot_reference' => 'SL-CN-0891',
                        'quantity_used'          => 2,
                    ],
                ],
            ])->save();
            $n++;
        }
        $this->command?->info("  Stamped {$n} work-order batch numbers.");
    }

    /**
     * ADV3 — make ONE work order a fully record-backed "hero" trace so the
     * flagship demo search resolves end-to-end (not narrated): real machine +
     * mold + output qty, a real GRN line whose material_lot_number matches the
     * WO's material_lot_references, and a passed outgoing QC inspection.
     */
    private function hardenHeroTrace(): void
    {
        $wo = WorkOrder::whereNotNull('batch_number')->orderBy('id')->first();
        if (! $wo) {
            $this->command?->warn('  No batch WO; skipping hero trace.');
            return;
        }

        $machineId = DB::table('machines')->min('id');
        $moldId    = DB::table('molds')->min('id');
        $grn       = DB::table('goods_receipt_notes')->orderBy('id')->first();
        $grnItem   = $grn ? DB::table('grn_items')->where('goods_receipt_note_id', $grn->id)->orderBy('id')->first() : null;

        // Point the WO's material trace at the REAL GRN line, and stamp that
        // GRN line with the matching lot so a material-lot search also resolves.
        $matLot   = 'MLOT-' . Carbon::parse($grn->received_date ?? now())->format('Ymd') . '-01';
        $supLot   = 'SL-TW-0234';
        if ($grnItem) {
            DB::table('grn_items')->where('id', $grnItem->id)->update([
                'material_lot_number'    => $matLot,
                'supplier_lot_reference' => $supLot,
                'updated_at'             => now(),
            ]);
        }

        $wo->forceFill([
            'machine_id'   => $wo->machine_id ?? $machineId,
            'mold_id'      => $wo->mold_id ?? $moldId,
            'quantity_good'     => max((int) $wo->quantity_good, 9955),
            'quantity_rejected' => max((int) $wo->quantity_rejected, 45),
            'material_lot_references' => [[
                'item_id'                => $grnItem->item_id ?? null,
                'item_code'              => 'RESIN-ABS',
                'item_name'              => 'Resin A (ABS)',
                'grn_number'             => $grn->grn_number ?? null,
                'material_lot_number'    => $matLot,
                'supplier_lot_reference' => $supLot,
                'quantity_used'          => 150,
            ]],
        ])->save();

        // Passed outgoing QC inspection linked to the WO (trace forward leg).
        $exists = DB::table('inspections')
            ->where('entity_type', 'work_order')->where('entity_id', $wo->id)
            ->where('stage', 'outgoing')->exists();
        if (! $exists) {
            $specId = DB::table('inspection_specs')->where('product_id', $wo->product_id)->value('id');
            DB::table('inspections')->insert([
                'inspection_number'  => 'QC-' . Carbon::now()->format('Ym') . '-9001',
                'stage'              => 'outgoing',
                'status'             => 'passed',
                'product_id'         => $wo->product_id,
                'inspection_spec_id' => $specId,
                'entity_type'        => 'work_order',
                'entity_id'          => $wo->id,
                'batch_quantity'     => 10000,
                'sample_size'        => 200,
                'aql_code'           => 'II',
                'accept_count'       => 200,
                'reject_count'       => 0,
                'defect_count'       => 0,
                'inspector_id'       => $this->admin()?->id,
                'started_at'         => Carbon::now()->subHours(2),
                'completed_at'       => Carbon::now()->subHour(),
                'notes'              => 'Demo — AQL 0.65 Level II outgoing inspection, accepted.',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }

        $this->command?->info("  Hardened hero trace on {$wo->batch_number} (machine/mold/GRN/QC linked).");
    }

    /** ADV3 — one shipment lot per delivery, tied to the delivery's work orders. */
    private function seedShipmentLots(): void
    {
        if (ShipmentLot::count() > 0) {
            $this->command?->info('  Shipment lots already present.');
            return;
        }

        $admin = $this->admin();
        $woIds = WorkOrder::orderBy('id')->pluck('id')->all();
        $n = 0;

        foreach (Delivery::orderBy('id')->get() as $i => $del) {
            $so = $del->sales_order_id ? SalesOrder::find($del->sales_order_id) : null;
            $customerId = $so?->customer_id;
            $productId = $so
                ? DB::table('sales_order_items')->where('sales_order_id', $so->id)->value('product_id')
                : null;
            $productId = $productId ?? DB::table('products')->min('id');
            if (! $customerId) {
                $customerId = DB::table('customers')->min('id');
            }
            if (! $customerId || ! $productId) {
                continue;
            }

            $date = Carbon::parse($del->delivered_at ?? $del->scheduled_date ?? now());
            ShipmentLot::create([
                'lot_number'     => 'LOT-' . $date->format('Ymd') . '-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'delivery_id'    => $del->id,
                'customer_id'    => $customerId,
                'product_id'     => $productId,
                'work_order_ids' => array_slice($woIds, 0, 2),
                'quantity'       => 5000,
                'lot_date'       => $date->toDateString(),
                'coc_path'       => null,
                'created_by'     => $admin?->id,
            ]);
            $n++;
        }
        $this->command?->info("  Created {$n} shipment lots.");
    }

    /** ADV7 — proof-of-delivery record + receiver fields for delivered deliveries. */
    private function seedDeliveryProofs(): void
    {
        if (DeliveryProof::count() > 0) {
            $this->command?->info('  Delivery proofs already present.');
            return;
        }

        $admin = $this->admin();
        $n = 0;
        // Any delivery that has left / arrived is a candidate; fall back to all.
        $dels = Delivery::orderBy('id')->get();

        foreach ($dels as $del) {
            $when = Carbon::parse($del->delivered_at ?? $del->scheduled_date ?? now());
            DeliveryProof::create([
                'delivery_id' => $del->id,
                'proof_type'  => 'signed_dr',
                'file_name'   => 'signed_dr_' . $when->format('Ymd') . '.jpg',
                'file_path'   => 'delivery-proofs/signed_dr_' . $del->id . '.jpg',
                'file_size'   => 184320,
                'mime_type'   => 'image/jpeg',
                'uploaded_by' => $admin?->id,
                'notes'       => 'Demo — signed delivery receipt.',
            ]);

            $del->forceFill([
                'receiver_name'     => $del->receiver_name ?? 'Maria Santos',
                'receiver_position' => $del->receiver_position ?? 'Purchasing Officer',
                'received_at'       => $del->received_at ?? $when->copy()->setTime(10, 45),
            ])->save();
            $n++;
        }
        $this->command?->info("  Created {$n} delivery proofs.");
    }

    /** ADV1 — mark a finalized payroll period disbursed + attach a bank proof. */
    private function seedDisbursement(): void
    {
        if (DisbursementProof::count() > 0) {
            $this->command?->info('  Disbursement proof already present.');
            return;
        }

        $period = PayrollPeriod::where('status', 'finalized')->orderByDesc('id')->first()
            ?? PayrollPeriod::orderByDesc('id')->first();
        if (! $period) {
            $this->command?->warn('  No payroll period; skipping disbursement.');
            return;
        }

        $admin = $this->admin();
        $amount = (float) (DB::table('payrolls')->where('payroll_period_id', $period->id)->sum('net_pay') ?: 2847500);

        DisbursementProof::create([
            'payroll_period_id'     => $period->id,
            'proof_type'            => 'bank_confirmation',
            'file_name'             => 'BDO_TransferConfirmation.pdf',
            'file_path'             => 'payroll-proofs/period_' . $period->id . '_confirmation.pdf',
            'bank_name'             => 'BDO Unibank',
            'transaction_reference' => 'TXN' . Carbon::now()->format('Ymd') . '001',
            'disbursed_amount'      => round($amount, 2),
            'disbursement_date'     => Carbon::now()->toDateString(),
            'uploaded_by'           => $admin?->id,
            'notes'                 => 'Demo — salaries transferred via BDO corporate banking.',
        ]);

        $period->forceFill([
            'disbursement_status' => 'disbursed',
            'disbursed_at'        => Carbon::now(),
            'disbursed_by'        => $admin?->id,
        ])->save();

        $this->command?->info("  Marked period #{$period->id} disbursed (₱" . number_format($amount, 2) . ').');
    }

    /** ADV12 — one finalized customer credit note against an existing invoice. */
    private function seedCreditNote(): void
    {
        if (DB::table('credit_notes')->count() > 0) {
            $this->command?->info('  Credit note already present.');
            return;
        }

        $admin = $this->admin();
        $invoice = Invoice::orderBy('id')->first();
        if (! $admin || ! $invoice) {
            $this->command?->warn('  No invoice/admin; skipping credit note.');
            return;
        }

        // A revenue/sales-returns account for the credit line.
        $account = Account::where('type', 'revenue')->orderBy('id')->first()
            ?? Account::orderBy('id')->first();

        /** @var CreditNoteService $svc */
        $svc = app(CreditNoteService::class);
        $cn = $svc->create([
            'type'        => 'customer',
            'customer_id' => $invoice->customer_id,
            'invoice_id'  => $invoice->id,
            'date'        => Carbon::now()->toDateString(),
            'is_vatable'  => true,
            'reason'      => 'Demo — credit for returned units (RMA disposition: scrap).',
            'lines'       => [[
                'account_id'  => $account->id,
                'description' => 'Sales return — dimensional non-conformance',
                'amount'      => '12500.00',
            ]],
        ], $admin);

        $svc->finalize($cn->fresh(), $admin);
        $this->command?->info("  Created + finalized credit note #{$cn->id}.");
    }

    /** ADV9 — FY2026 department budgets, one deliberately near-critical (98%). */
    private function seedBudgets(): void
    {
        if (Budget::count() > 0) {
            $this->command?->info('  Budgets already present.');
            return;
        }

        $admin = $this->admin();
        $fyId = DB::table('fiscal_years')->where('year', 2026)->value('id');
        if (! $fyId) {
            $fyId = DB::table('fiscal_years')->insertGetId([
                'year'       => 2026,
                'start_date' => '2026-01-01',
                'end_date'   => '2026-12-31',
                'status'     => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $expenseAccounts = Account::where('type', 'expense')->orderBy('id')->pluck('id')->all();
        if (empty($expenseAccounts)) {
            $expenseAccounts = Account::orderBy('id')->pluck('id')->take(3)->all();
        }

        // dept name/code, annual allocation, spent % (last one is critical).
        $plan = [
            ['dept' => 'PROD', 'name' => 'Production Operating Budget', 'alloc' => 18500000, 'spentPct' => 0.50],
            ['dept' => 'FIN',  'name' => 'Finance & Admin Budget',      'alloc' => 5200000,  'spentPct' => 0.60],
            ['dept' => 'MAINT','name' => 'Maintenance Budget',          'alloc' => 4500000,  'spentPct' => 0.98],
        ];

        $n = 0;
        foreach ($plan as $p) {
            $dept = Department::where('code', $p['dept'])->first() ?? Department::orderBy('id')->skip($n)->first();
            $spent = round($p['alloc'] * $p['spentPct'], 2);

            $budget = Budget::create([
                'fiscal_year_id'  => $fyId,
                'department_id'   => $dept?->id,
                'budget_type'     => 'department',
                'name'            => $p['name'],
                'total_allocated' => $p['alloc'],
                'total_spent'     => $spent,
                'total_committed' => round($p['alloc'] * 0.05, 2),
                'status'          => 'active',
                'submitted_by'    => $admin?->id,
                'submitted_at'    => now()->subDays(60),
                'approved_by'     => $admin?->id,
                'approved_at'     => now()->subDays(50),
            ]);

            // Spread the allocation across up to 3 expense accounts, evenly by month.
            $accts = array_slice($expenseAccounts, 0, 3) ?: [null];
            $perAccountAnnual = $p['alloc'] / max(count($accts), 1);
            $perMonth = round($perAccountAnnual / 12, 2);
            $perAccountActual = round($spent / max(count($accts), 1), 2);

            foreach ($accts as $acctId) {
                if (! $acctId) {
                    continue;
                }
                BudgetLineItem::create([
                    'budget_id'    => $budget->id,
                    'account_id'   => $acctId,
                    'jan' => $perMonth, 'feb' => $perMonth, 'mar' => $perMonth,
                    'apr' => $perMonth, 'may' => $perMonth, 'jun' => $perMonth,
                    'jul' => $perMonth, 'aug' => $perMonth, 'sep' => $perMonth,
                    'oct' => $perMonth, 'nov' => $perMonth, 'dec' => $perMonth,
                    'actual_total' => $perAccountActual,
                ]);
            }
            $n++;
        }
        $this->command?->info("  Created {$n} FY2026 department budgets (1 near-critical).");
    }
}
