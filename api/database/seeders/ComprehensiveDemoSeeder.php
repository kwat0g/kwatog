<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Quality\Models\DefectType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Comprehensive demo data seeder — fills every module with realistic data.
 * Idempotent: skips sections where data already exists.
 * Column names match the actual database schema (verified via information_schema).
 */
class ComprehensiveDemoSeeder extends Seeder
{
    private ?User $admin = null;

    public function run(): void
    {
        $this->admin = User::query()
            ->whereHas('role', fn($q) => $q->where('slug', 'system_admin'))
            ->orderBy('id')
            ->first() ?? User::first();

        if (!$this->admin) {
            $this->command?->warn('No admin user found, skipping.');
            return;
        }

        // Clear previously seeded data for a clean run
        DB::statement('SET session_replication_role = replica;');
        $this->truncateAll();
        DB::statement('SET session_replication_role = DEFAULT;');

        $this->seedPayroll();
        $this->seedInvoices();
        $this->seedBills();
        $this->seedPurchaseRequests();
        $this->seedPurchaseOrders();
        $this->seedApprovedSuppliers();
        $this->seedGRNs();
        $this->seedMaterialIssues();
        $this->seedStockCounts();
        $this->seedTransferOrders();
        $this->seedBudgets();
        $this->seedJournalEntries();
        $this->seedLoans();
        $this->seedLeaveRequests();
        $this->seedReturnRequests();
        $this->seedDeliveries();
        $this->seedNCRs();
        $this->seedInspections();
        $this->seedOvertime();
        $this->seedSupplierPerformance();
        $this->seedAssetDepreciations();
        $this->seedInspectionSpecs();
        $this->seedDeliveryItems();
        $this->seedShipments();

        $this->command?->info('ComprehensiveDemoSeeder: ALL modules populated.');
    }

    private function truncateAll(): void
    {
        $tables = [
            'payroll_periods', 'payrolls', 'invoices', 'bills',
            'purchase_requests', 'purchase_request_items',
            'purchase_orders', 'purchase_order_items',
            'approved_suppliers', 'goods_receipt_notes', 'grn_items',
            'material_issue_slips', 'material_issue_slip_items',
            'stock_count_sessions', 'stock_count_items',
            'transfer_orders',
            'budgets', 'budget_line_items', 'budget_transfers',
            'journal_entries', 'journal_entry_lines',
            'employee_loans', 'loan_payments', 'leave_requests',
            'return_requests', 'return_request_items',
            'deliveries',            'delivery_items',
            'non_conformance_reports', 'ncr_actions',
            'inspections',
            'inspection_specs', 'inspection_spec_items', 'inspection_measurements',
            'overtime_requests',
            'supplier_performance_snapshots',
            'asset_depreciations',
            'shipments',
        ];
        foreach ($tables as $t) {
            DB::table($t)->truncate();
        }
    }

    /* ===================================================================
     * 1. PAYROLL
     * =================================================================== */
    private function seedPayroll(): void
    {
        $employees = Employee::where('status', 'active')->get();
        if ($employees->isEmpty()) {
            $this->command?->warn('[Payroll] No active employees, skipping.');
            return;
        }

        $now = Carbon::now();

        // Past period (finalized)
        $pastId = DB::table('payroll_periods')->insertGetId([
            'period_start'        => $now->copy()->subMonth()->startOfMonth()->toDateString(),
            'period_end'          => $now->copy()->subMonth()->startOfMonth()->addDays(14)->toDateString(),
            'payroll_date'        => $now->copy()->subMonth()->startOfMonth()->addDays(20)->toDateString(),
            'is_first_half'       => false,
            'is_thirteenth_month' => false,
            'status'              => 'finalized',
            'created_by'          => $this->admin->id,
            'created_at'          => $now,
            'updated_at'          => $now,
            'disbursement_status' => 'paid',
            'disbursed_at'        => $now->copy()->subDays(5),
            'disbursed_by'        => $this->admin->id,
        ]);

        // Current period (draft — newly created, not yet processed).
        DB::table('payroll_periods')->insert([
            'period_start'        => $now->copy()->startOfMonth()->toDateString(),
            'period_end'          => $now->copy()->startOfMonth()->addDays(14)->toDateString(),
            'payroll_date'        => $now->copy()->startOfMonth()->addDays(20)->toDateString(),
            'is_first_half'       => true,
            'is_thirteenth_month' => false,
            'status'              => 'draft',
            'created_by'          => $this->admin->id,
            'created_at'          => $now,
            'updated_at'          => $now,
            'disbursement_status' => 'pending',
        ]);

        $created = 0;
        foreach ($employees as $emp) {
            $pay = $emp->basic_monthly_salary ?? 25000;
            $ded = round($pay * 0.17, 2);
            DB::table('payrolls')->insert([
                'payroll_period_id' => $pastId,
                'employee_id'       => $emp->id,
                'pay_type'          => 'salary',
                'days_worked'       => 15,
                'basic_pay'         => $pay,
                'overtime_pay'      => 0,
                'night_diff_pay'    => 0,
                'holiday_pay'       => 0,
                'gross_pay'         => $pay,
                'sss_ee'            => round($pay * 0.045, 2),
                'sss_er'            => round($pay * 0.045, 2),
                'philhealth_ee'     => round($pay * 0.025, 2),
                'philhealth_er'     => round($pay * 0.025, 2),
                'pagibig_ee'        => 100,
                'pagibig_er'        => 100,
                'withholding_tax'   => round($pay * 0.08, 2),
                'loan_deductions'   => round($pay * 0.02, 2),
                'other_deductions'  => 0,
                'adjustment_amount' => 0,
                'total_deductions'  => $ded,
                'net_pay'           => round($pay - $ded, 2),
                'computed_at'       => $now->copy()->subDays(9),
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);
            $created++;
        }

        $this->command?->info("[Payroll] Created 1 finalized period with {$created} payrolls + 1 open period.");
    }

    /* ===================================================================
     * 2. INVOICES
     * =================================================================== */
    private function seedInvoices(): void
    {
        $customers = DB::table('customers')->limit(2)->get();
        if ($customers->isEmpty()) {
            $this->command?->warn('[Invoices] No customers, skipping.');
            return;
        }

        $now = Carbon::now();
        $invData = [
            ['number' => '0001', 'status' => 'finalized', 'sub' => 223214.29, 'total' => 250000.00, 'paid' => 0, 'daysAgo' => 20],
            ['number' => '0002', 'status' => 'paid',      'sub' => 160714.29, 'total' => 180000.00, 'paid' => 180000, 'daysAgo' => 15],
            ['number' => '0003', 'status' => 'partial',   'sub' => 84821.43,  'total' => 95000.00,  'paid' => 57000,  'daysAgo' => 10],
        ];

        foreach ($invData as $i => $d) {
            $date = $now->copy()->subDays($d['daysAgo']);
            DB::table('invoices')->insert([
                'invoice_number' => 'INV-' . $now->format('Ymd') . '-' . $d['number'],
                'customer_id'    => $customers[min($i, count($customers) - 1)]->id,
                'date'           => $date->toDateString(),
                'due_date'       => $date->copy()->addDays(30)->toDateString(),
                'is_vatable'     => true,
                'subtotal'       => $d['sub'],
                'vat_amount'     => round($d['sub'] * 0.12, 2),
                'total_amount'   => $d['total'],
                'amount_paid'    => $d['paid'],
                'balance'        => $d['total'] - $d['paid'],
                'status'         => $d['status'],
                'created_by'     => $this->admin->id,
            ]);
        }
        $this->command?->info('[Invoices] Created 3 demo invoices (finalized, paid, partial).');
    }

    /* ===================================================================
     * 3. BILLS
     * =================================================================== */
    private function seedBills(): void
    {
        $vendor = DB::table('vendors')->first();
        if (!$vendor) return;

        $now = Carbon::now();
        $billData = [
            ['number' => '0001', 'status' => 'paid',    'sub' => 133928.57, 'total' => 150000.00, 'paid' => 150000, 'daysAgo' => 25],
            ['number' => '0002', 'status' => 'unpaid', 'sub' => 75892.86,  'total' => 85000.00,  'paid' => 0,      'daysAgo' => 10],
        ];

        foreach ($billData as $d) {
            $date = $now->copy()->subDays($d['daysAgo']);
            DB::table('bills')->insert([
                'bill_number'  => 'BILL-' . $now->format('Ymd') . '-' . $d['number'],
                'vendor_id'    => $vendor->id,
                'date'         => $date->toDateString(),
                'due_date'     => $date->copy()->addDays(30)->toDateString(),
                'is_vatable'   => true,
                'subtotal'     => $d['sub'],
                'vat_amount'   => round($d['sub'] * 0.12, 2),
                'total_amount' => $d['total'],
                'amount_paid'  => $d['paid'],
                'balance'      => $d['total'] - $d['paid'],
                'status'       => $d['status'],
                'created_by'   => $this->admin->id,
            ]);
        }
        $this->command?->info('[Bills] Created 2 demo bills.');
    }

    /* ===================================================================
     * 4. PURCHASE REQUESTS
     * =================================================================== */
    private function seedPurchaseRequests(): void
    {
        $items = Item::limit(4)->get();
        if ($items->isEmpty()) return;

        $now = Carbon::now();
        for ($i = 0; $i < 3; $i++) {
            $prId = DB::table('purchase_requests')->insertGetId([
                'pr_number'           => 'PR-' . $now->format('Ymd') . '-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT),
                'requested_by'        => $this->admin->id,
                'date'                => $now->copy()->subDays(10 - $i * 3)->toDateString(),
                'reason'              => 'Operational requirement — demo PR ' . ($i + 1),
                'priority'            => ['urgent', 'normal', 'normal'][$i],
                'status'              => ['approved', 'pending', 'draft'][$i],
                'current_approval_step' => $i === 0 ? 2 : 0,
                'is_urgent'           => $i === 0,
            ]);

            $item = $items[$i % $items->count()];
            DB::table('purchase_request_items')->insert([
                'purchase_request_id' => $prId,
                'item_id'             => $item->id,
                'description'         => $item->name . ' (demo)',
                'quantity'            => 10 + $i * 5,
                'estimated_unit_price' => round((float)($item->standard_cost ?? 100), 2),
            ]);
        }
        $this->command?->info('[PRs] Created 3 demo purchase requests.');
    }

    /* ===================================================================
     * 5. PURCHASE ORDERS
     * =================================================================== */
    private function seedPurchaseOrders(): void
    {
        $pr = DB::table('purchase_requests')->where('status', 'approved')->first();
        $vendor = DB::table('vendors')->first();
        if (!$pr || !$vendor) return;

        $now = Carbon::now();

        // PO 1 — confirmed, linked to PR
        $poId = DB::table('purchase_orders')->insertGetId([
            'po_number'              => 'PO-' . $now->format('Ymd') . '-0001',
            'vendor_id'              => $vendor->id,
            'purchase_request_id'    => $pr->id,
            'date'                   => $now->copy()->subDays(5)->toDateString(),
            'expected_delivery_date' => $now->copy()->addDays(15)->toDateString(),
            'subtotal'               => 25000.00,
            'vat_amount'             => 3000.00,
            'total_amount'           => 28000.00,
            'is_vatable'             => true,
            'status'                 => 'approved',
            'current_approval_step'  => 2,
            'created_by'             => $this->admin->id,
        ]);

        // PO items from PR items
        $prItems = DB::table('purchase_request_items')
            ->where('purchase_request_id', $pr->id)
            ->get();

        foreach ($prItems as $ri) {
            $item = Item::find($ri->item_id);
            if (!$item) continue;
            $total = round((float)$ri->quantity * (float)($ri->estimated_unit_price ?? 100), 2);
            DB::table('purchase_order_items')->insert([
                'purchase_order_id'  => $poId,
                'item_id'            => $item->id,
                'purchase_request_item_id' => $ri->id,
                'description'        => $item->name,
                'quantity'           => $ri->quantity,
                'unit_price'         => (float)($ri->estimated_unit_price ?? 100),
                'total'              => $total,
                'quantity_received'  => 0,
            ]);
        }

        // PO 2 — draft (no PR link)
        DB::table('purchase_orders')->insert([
            'po_number'              => 'PO-' . $now->format('Ymd') . '-0002',
            'vendor_id'              => $vendor->id,
            'date'                   => $now->copy()->subDays(2)->toDateString(),
            'expected_delivery_date' => $now->copy()->addDays(20)->toDateString(),
            'subtotal'               => 18000.00,
            'vat_amount'             => 2160.00,
            'total_amount'           => 20160.00,
            'is_vatable'             => true,
            'status'                 => 'draft',
            'current_approval_step'  => 0,
            'created_by'             => $this->admin->id,
        ]);

        $this->command?->info('[POs] Created 2 demo purchase orders (1 approved, 1 draft).');
    }

    /* ===================================================================
     * 6. APPROVED SUPPLIERS
     * =================================================================== */
    private function seedApprovedSuppliers(): void
    {
        $vendors = DB::table('vendors')->get();
        $items = Item::limit(6)->get();
        $count = 0;
        foreach ($vendors as $v) {
            foreach ($items as $item) {
                DB::table('approved_suppliers')->insert([
                    'vendor_id'      => $v->id,
                    'item_id'        => $item->id,
                    'lead_time_days' => rand(5, 20),
                    'is_preferred'   => rand(0, 1) ? true : false,
                ]);
                $count++;
            }
        }
        $this->command?->info("[Approved Suppliers] Created {$count} records.");
    }

    /* ===================================================================
     * 7. GOODS RECEIPT NOTES
     * =================================================================== */
    private function seedGRNs(): void
    {
        $po = DB::table('purchase_orders')->where('status', 'approved')->first();
        $vendor = DB::table('vendors')->first();
        if (!$po || !$vendor) return;

        $now = Carbon::now();

        // Update PO to have items
        $poItems = DB::table('purchase_order_items')
            ->where('purchase_order_id', $po->id)
            ->get();

        if ($poItems->isEmpty()) {
            // Create a PO item directly if the PR-based one didn't work
            $item = Item::first();
            if (!$item) return;
            DB::table('purchase_order_items')->insert([
                'purchase_order_id' => $po->id,
                'item_id'           => $item->id,
                'description'       => $item->name,
                'quantity'          => 10,
                'unit_price'        => 100,
                'total'             => 1000,
                'quantity_received' => 0,
            ]);
            $poItems = DB::table('purchase_order_items')
                ->where('purchase_order_id', $po->id)
                ->get();
        }

        $loc = WarehouseLocation::first();

        $grnId = DB::table('goods_receipt_notes')->insertGetId([
            'grn_number'        => 'GRN-' . $now->format('Ymd') . '-0001',
            'purchase_order_id' => $po->id,
            'vendor_id'         => $vendor->id,
            'received_date'     => $now->copy()->subDays(1)->toDateString(),
            'received_by'       => $this->admin->id,
            'status'            => 'accepted',
            'remarks'           => 'Demo GRN — all items received in good condition',
        ]);

        foreach ($poItems as $poItem) {
            DB::table('grn_items')->insert([
                'goods_receipt_note_id'  => $grnId,
                'purchase_order_item_id' => $poItem->id,
                'item_id'                => $poItem->item_id,
                'location_id'            => $loc?->id ?? 1,
                'quantity_received'      => $poItem->quantity,
                'quantity_accepted'      => $poItem->quantity,
                'unit_cost'              => $poItem->unit_price,
            ]);
            DB::table('purchase_order_items')
                ->where('id', $poItem->id)
                ->update(['quantity_received' => $poItem->quantity]);
        }

        $this->command?->info('[GRNs] Created 1 demo GRN with items received.');
    }

    /* ===================================================================
     * 8. MATERIAL ISSUE SLIPS
     * =================================================================== */
    private function seedMaterialIssues(): void
    {
        $item = Item::first();
        $loc = WarehouseLocation::first();
        if (!$item || !$loc) return;

        $now = Carbon::now();
        $misId = DB::table('material_issue_slips')->insertGetId([
            'slip_number' => 'MIS-' . $now->format('Ymd') . '-0001',
            'issued_date' => $now->copy()->subDays(2)->toDateString(),
            'issued_by'   => $this->admin->id,
            'created_by'  => $this->admin->id,
            'status'      => 'issued',
            'total_value' => round(50 * (float)($item->standard_cost ?? 100), 2),
            'remarks'     => 'Demo material issue for production',
        ]);

        DB::table('material_issue_slip_items')->insert([
            'material_issue_slip_id' => $misId,
            'item_id'               => $item->id,
            'location_id'           => $loc->id,
            'quantity_issued'       => 50,
            'unit_cost'             => round((float)($item->standard_cost ?? 100), 2),
            'total_cost'            => round(50 * (float)($item->standard_cost ?? 100), 2),
        ]);

        $this->command?->info('[Material Issues] Created 1 demo material issue slip.');
    }

    /* ===================================================================
     * 9. STOCK COUNTS
     * =================================================================== */
    private function seedStockCounts(): void
    {
        $loc = WarehouseLocation::first();
        if (!$loc) return;

        $now = Carbon::now();
        $sessionId = DB::table('stock_count_sessions')->insertGetId([
            'session_number'   => 'SC-' . $now->format('Ymd') . '-0001',
            'title'            => 'Annual Physical Count ' . $now->year,
            'scope'            => 'full',
            'status'           => 'completed',
            'total_locations'  => 1,
            'counted_locations' => 1,
            'variance_count'   => 0,
            'variance_value'   => 0,
            'created_by'       => $this->admin->id,
        ]);

        $items = Item::limit(5)->get();
        foreach ($items as $item) {
            $sl = StockLevel::where('item_id', $item->id)
                ->where('location_id', $loc->id)->first();
            $systemQty = $sl ? (int)$sl->quantity : 0;
            $variance = rand(-5, 5);
            DB::table('stock_count_items')->insert([
                'session_id'       => $sessionId,
                'location_id'      => $loc->id,
                'item_id'          => $item->id,
                'system_quantity'  => $systemQty,
                'counted_quantity' => $systemQty + $variance,
                'variance'         => $variance,
                'variance_percent' => $systemQty > 0 ? round(abs($variance) / $systemQty * 100, 2) : 0,
                'status'           => 'counted',
            ]);
        }

        $this->command?->info('[Stock Counts] Created 1 stock count session.');
    }

    /* ===================================================================
     * 10. TRANSFER ORDERS
     * =================================================================== */
    private function seedTransferOrders(): void
    {
        $fromLoc = WarehouseLocation::orderBy('id')->first();
        $toLoc = WarehouseLocation::orderBy('id')->skip(1)->first();
        $item = Item::first();
        if (!$fromLoc || !$toLoc || !$item) return;

        $now = Carbon::now();
        DB::table('transfer_orders')->insert([
            'transfer_number'   => 'TO-' . $now->format('Ymd') . '-0001',
            'from_location_id'  => $fromLoc->id,
            'to_location_id'    => $toLoc->id,
            'item_id'           => $item->id,
            'quantity'          => 25,
            'status'            => 'completed',
            'created_by'        => $this->admin->id,
            'transferred_at'    => $now->copy()->subDays(2),
        ]);

        DB::table('transfer_orders')->insert([
            'transfer_number'   => 'TO-' . $now->format('Ymd') . '-0002',
            'from_location_id'  => $fromLoc->id,
            'to_location_id'    => $toLoc->id,
            'item_id'           => $item->id,
            'quantity'          => 50,
            'status'            => 'pending',
            'created_by'        => $this->admin->id,
        ]);

        $this->command?->info('[Transfer Orders] Created 2 demo transfer orders.');
    }

    /* ===================================================================
     * 11. BUDGETS
     * =================================================================== */
    private function seedBudgets(): void
    {
        $fy = FiscalYear::first();
        if (!$fy) {
            $this->command?->warn('[Budgets] No fiscal year, skipping.');
            return;
        }

        $accounts = Account::where(function ($q) {
            $q->where('type', 'expense')->orWhere('type', 'expenses');
        })->limit(4)->get();

        if ($accounts->isEmpty()) $accounts = Account::limit(4)->get();
        if ($accounts->isEmpty()) {
            $this->command?->warn('[Budgets] No accounts, skipping.');
            return;
        }

        $budgetId = DB::table('budgets')->insertGetId([
            'fiscal_year_id'  => $fy->id,
            'budget_type'     => 'operating',
            'name'            => 'Annual Operating Budget ' . $fy->year,
            'total_allocated' => 5_000_000,
            'total_spent'     => 1_200_000,
            'total_committed' => 200_000,
            'status'          => 'approved',
        ]);

        $allocations = [
            ['name' => 'Raw Materials', 'annual' => 2_000_000, 'actual' => 600_000],
            ['name' => 'Labor',         'annual' => 1_500_000, 'actual' => 450_000],
            ['name' => 'Operations',    'annual' => 600_000,  'actual' => 100_000],
            ['name' => 'Maintenance',   'annual' => 400_000,  'actual' => 50_000],
        ];

        foreach ($allocations as $i => $a) {
            DB::table('budget_line_items')->insert([
                'budget_id'    => $budgetId,
                'account_id'   => $accounts[$i % count($accounts)]->id,
                'jan'          => round($a['annual'] / 12, 2),
                'feb'          => round($a['annual'] / 12, 2),
                'mar'          => round($a['annual'] / 12, 2),
                'apr'          => round($a['annual'] / 12, 2),
                'may'          => round($a['annual'] / 12, 2),
                'jun'          => round($a['annual'] / 12, 2),
                'jul'          => round($a['annual'] / 12, 2),
                'aug'          => round($a['annual'] / 12, 2),
                'sep'          => round($a['annual'] / 12, 2),
                'oct'          => round($a['annual'] / 12, 2),
                'nov'          => round($a['annual'] / 12, 2),
                'dec'          => round($a['annual'] / 12, 2),
            ]);
        }

        $fromLine = DB::table('budget_line_items')->where('budget_id', $budgetId)->first();
        $toLine = DB::table('budget_line_items')->where('budget_id', $budgetId)->skip(1)->first();
        if ($fromLine && $toLine) {
            DB::table('budget_transfers')->insert([
                'from_budget_line_id' => $fromLine->id,
                'to_budget_line_id'   => $toLine->id,
                'amount'              => 50_000,
                'reason'              => 'Reallocation for urgent raw material purchase',
                'status'              => 'approved',
                'requested_by'        => $this->admin->id,
            ]);
        }

        $this->command?->info('[Budgets] Created 1 budget with 4 line items + 1 transfer.');
    }

    /* ===================================================================
     * 12. JOURNAL ENTRIES
     * =================================================================== */
    private function seedJournalEntries(): void
    {
        $accounts = Account::limit(4)->get();
        if ($accounts->count() < 2) return;

        $now = Carbon::now();
        for ($i = 0; $i < 3; $i++) {
            $entryId = DB::table('journal_entries')->insertGetId([
                'entry_number' => 'JE-' . $now->format('Ymd') . '-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT),
                'date'         => $now->copy()->subDays(10 - $i * 3)->toDateString(),
                'total_debit'  => 50000 + $i * 10000,
                'total_credit' => 50000 + $i * 10000,
                'status'       => 'posted',
            ]);

            DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $entryId,
                'account_id'       => $accounts[0]->id,
                'line_no'          => 1,
                'debit'            => 50000 + $i * 10000,
                'credit'           => 0,
            ]);
            DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $entryId,
                'account_id'       => $accounts[1]->id,
                'line_no'          => 2,
                'debit'            => 0,
                'credit'           => 50000 + $i * 10000,
            ]);
        }

        $this->command?->info('[JEs] Created 3 demo journal entries.');
    }

    /* ===================================================================
     * 13. LOANS
     * =================================================================== */
    private function seedLoans(): void
    {
        $employees = Employee::where('status', 'active')->limit(3)->get();
        if ($employees->isEmpty()) return;

        $now = Carbon::now();
        foreach ($employees as $i => $emp) {
            $principal = 10000 + $i * 5000;
            $payPeriods = 12;
            $monthly = round($principal / $payPeriods, 2);
            $paidPeriods = $i === 0 ? $payPeriods : ($i === 1 ? 6 : 0);
            $totalPaid = round($monthly * $paidPeriods, 2);
            $balance = round(($principal * 1.05) - $totalPaid, 2);

            DB::table('employee_loans')->insert([
                'loan_no'               => 'LN-' . $now->format('Ymd') . '-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT),
                'employee_id'           => $emp->id,
                'loan_type'             => ['Salary Loan', 'Emergency Loan', 'Equipment Loan'][$i],
                'principal'             => $principal,
                'interest_rate'         => 0.05,
                'monthly_amortization'  => $monthly,
                'total_paid'            => $totalPaid,
                'balance'               => $balance,
                'start_date'            => $now->copy()->subMonths($payPeriods)->toDateString(),
                'end_date'              => $now->copy()->toDateString(),
                'pay_periods_total'     => $payPeriods,
                'pay_periods_remaining' => $payPeriods - $paidPeriods,
                'approval_chain_size'   => 2,
                'purpose'               => 'Demo loan purpose',
                'status'                => $i === 0 ? 'paid' : ($i === 1 ? 'active' : 'pending'),
            ]);
        }

        // Loan payments for active loan
        $activeLoan = DB::table('employee_loans')->where('status', 'active')->first();
        if ($activeLoan) {
            for ($p = 0; $p < 6; $p++) {
                DB::table('loan_payments')->insert([
                    'loan_id'       => $activeLoan->id,
                    'amount'        => $activeLoan->monthly_amortization,
                    'payment_date'  => $now->copy()->subMonths(6 - $p - 1)->toDateString(),
                    'payment_type'  => 'payroll_deduction',
                ]);
            }
        }

        $this->command?->info('[Loans] Created 3 employee loans (paid, active, approved).');
    }

    /* ===================================================================
     * 14. LEAVE REQUESTS
     * =================================================================== */
    private function seedLeaveRequests(): void
    {
        $employees = Employee::where('status', 'active')->limit(3)->get();
        $leaveType = LeaveType::first();
        if ($employees->isEmpty() || !$leaveType) return;

        $now = Carbon::now();
        foreach ($employees as $i => $emp) {
            DB::table('leave_requests')->insert([
                'leave_request_no' => 'LR-' . $now->format('Ymd') . '-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT),
                'employee_id'      => $emp->id,
                'leave_type_id'    => $leaveType->id,
                'start_date'       => $now->copy()->addDays($i * 14)->toDateString(),
                'end_date'         => $now->copy()->addDays($i * 14 + 2)->toDateString(),
                'days'             => 3,
                'reason'           => 'Personal leave — demo request ' . ($i + 1),
                'status'           => ['approved', 'pending_dept', 'rejected'][$i],
            ]);
        }

        $this->command?->info('[Leave] Created 3 demo leave requests.');
    }

    /* ===================================================================
     * 15. RETURN REQUESTS
     * =================================================================== */
    private function seedReturnRequests(): void
    {
        $customer = DB::table('customers')->first();
        if (!$customer) return;

        $now = Carbon::now();
        for ($i = 0; $i < 3; $i++) {
            $rrId = DB::table('return_requests')->insertGetId([
                'rma_number'         => 'RMA-' . $now->format('Ymd') . '-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT),
                'type'               => 'customer_return',
                'customer_id'        => $customer->id,
                'reason_code'        => ['defective', 'wrong_item', 'damaged'][$i],
                'reason_description' => ['Defective product', 'Wrong item shipped', 'Damaged in transit'][$i],
                'status'             => ['pending_approval', 'approved', 'completed'][$i],
                'return_date'        => $now->copy()->subDays(10 - $i * 3)->toDateString(),
                'created_by'         => $this->admin->id,
            ]);

            $item = Item::first();
            if ($item) {
            DB::table('return_request_items')->insert([
                'return_request_id' => $rrId,
                'item_id'           => $item->id,
                'quantity'          => 5 + $i * 3,
                'returned_quantity' => $i === 2 ? 5 + $i * 3 : 0,
                'unit_price'        => round((float)($item->standard_cost ?? 100), 2),
                'total'             => round((5 + $i * 3) * (float)($item->standard_cost ?? 100), 2),
                'reason'            => 'Customer reported issue',
            ]);
            }
        }

        $this->command?->info('[Return Mgmt] Created 3 demo return requests.');
    }

    /* ===================================================================
     * 16. DELIVERIES
     * =================================================================== */
    private function seedDeliveries(): void
    {
        $so = DB::table('sales_orders')->whereIn('status', ['confirmed', 'completed'])->first();
        if (!$so) return;

        $now = Carbon::now();
        DB::table('deliveries')->insert([
            'delivery_number' => 'DEL-' . $now->format('Ymd') . '-0001',
            'sales_order_id'  => $so->id,
            'scheduled_date'  => $now->copy()->subDays(3)->toDateString(),
            'status'          => 'delivered',
            'delivered_at'    => $now->copy()->subDays(3),
            'created_by'      => $this->admin->id,
        ]);

        DB::table('deliveries')->insert([
            'delivery_number' => 'DEL-' . $now->format('Ymd') . '-0002',
            'sales_order_id'  => $so->id,
            'scheduled_date'  => $now->copy()->addDays(5)->toDateString(),
            'status'          => 'scheduled',
            'created_by'      => $this->admin->id,
        ]);

        $this->command?->info('[Deliveries] Created 2 demo deliveries.');
    }

    /* ===================================================================
     * 17. QUALITY — NCRs
     * =================================================================== */
    private function seedNCRs(): void
    {
        $now = Carbon::now();
        for ($i = 0; $i < 3; $i++) {
            $ncrId = DB::table('non_conformance_reports')->insertGetId([
                'ncr_number'         => 'NCR-' . $now->format('Ymd') . '-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT),
                'source'   => ['inspection_fail', 'customer_complaint', 'inspection_fail'][$i],
                'severity' => ['high', 'medium', 'low'][$i],
                'status'   => ['open', 'in_progress', 'closed'][$i],
                'defect_description' => ['Surface cracks detected on finished product', 'Dimensional tolerance exceeded spec', 'Color variation in batch'][$i],
                'affected_quantity'  => 5 + $i * 10,
                'created_by'         => $this->admin->id,
                'is_auto_generated' => false,
            ]);

            if ($i === 2) {
                DB::table('ncr_actions')->insert([
                    'ncr_id'       => $ncrId,
                    'action_type'  => 'corrective',
                    'description'  => 'Adjusted machine calibration and retrained operator',
                    'performed_by' => $this->admin->id,
                    'performed_at' => $now->copy()->subDays(2),
                ]);
            }
        }

        $this->command?->info('[NCRs] Created 3 demo NCRs.');
    }

    /* ===================================================================
     * 18. QUALITY — INSPECTIONS
     * =================================================================== */
    private function seedInspections(): void
    {
        $product = DB::table('products')->first();
        if (!$product) {
            $this->command?->warn('[Inspections] No products, skipping.');
            return;
        }

        $now = Carbon::now();
        for ($i = 0; $i < 3; $i++) {
            $isPass = $i !== 2;
            DB::table('inspections')->insert([
                'inspection_number' => 'INS-' . $now->format('Ymd') . '-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT),
                'stage'  => ['in_process', 'outgoing', 'in_process'][$i],
                'status' => $isPass ? 'passed' : 'failed',
                'product_id'        => $product->id,
                'batch_quantity'    => 100,
                'sample_size'       => 10,
                'accept_count'      => $isPass ? 10 : 8,
                'reject_count'      => $isPass ? 0 : 2,
                'defect_count'      => $isPass ? 0 : 3,
            ]);
        }

        $this->command?->info('[Inspections] Created 3 demo inspections.');
    }

    /* ===================================================================
     * 19. OVERTIME REQUESTS
     * =================================================================== */
    private function seedOvertime(): void
    {
        $employees = Employee::where('status', 'active')->limit(3)->get();
        if ($employees->isEmpty()) return;

        $now = Carbon::now();
        foreach ($employees as $i => $emp) {
            DB::table('overtime_requests')->insert([
                'employee_id'      => $emp->id,
                'date'             => $now->copy()->subDays(5 - $i * 2)->toDateString(),
                'hours_requested'  => 3,
                'reason'           => 'Production backlog — demo overtime ' . ($i + 1),
                'status'           => ['approved', 'pending', 'approved'][$i],
            ]);
        }

        $this->command?->info('[Overtime] Created 3 demo overtime requests.');
    }

    /* ===================================================================
     * 20. SUPPLIER PERFORMANCE
     * =================================================================== */
    private function seedSupplierPerformance(): void
    {
        $vendors = DB::table('vendors')->get();
        $now = now();

        foreach ($vendors as $v) {
            DB::table('supplier_performance_snapshots')->insert([
                'vendor_id'               => $v->id,
                'period_year'             => $now->year,
                'period_month'            => $now->month,
                'on_time_delivery_rate'   => rand(75, 100),
                'quality_pass_rate'       => rand(85, 100),
                'price_variance_pct'      => round(rand(-5, 10), 1),
                'lead_time_variance_days' => rand(-2, 5),
                'overall_score'           => round(rand(75, 100) * rand(75, 100) / 100, 1),
                'po_count'                => rand(5, 20),
                'grn_count'               => rand(3, 15),
                'computed_at'             => $now,
            ]);
        }

        $this->command?->info('[Supplier Performance] Created ' . count($vendors) . ' performance snapshots.');
    }

    /* ===================================================================
     * 21. ASSET DEPRECIATIONS
     * =================================================================== */
    private function seedAssetDepreciations(): void
    {
        $assets = DB::table('assets')->get();
        if ($assets->isEmpty()) {
            $this->command?->warn('[Asset Depreciation] No assets, skipping.');
            return;
        }

        $months = [
            ['year' => 2026, 'month' => 1],
            ['year' => 2026, 'month' => 2],
            ['year' => 2026, 'month' => 3],
        ];

        $created = 0;
        foreach ($assets->take(20) as $asset) {
            $cost = (float) $asset->acquisition_cost;
            $salvage = (float) ($asset->salvage_value ?? 0);
            $life = (int) ($asset->useful_life_years ?? 5);
            $monthlyDep = $life > 0 ? round(($cost - $salvage) / ($life * 12), 2) : 0;
            if ($monthlyDep <= 0) continue;

            $accumulated = 0;
            foreach ($months as $m) {
                $accumulated += $monthlyDep;
                DB::table('asset_depreciations')->insert([
                    'asset_id'             => $asset->id,
                    'period_year'          => $m['year'],
                    'period_month'         => $m['month'],
                    'depreciation_amount'  => $monthlyDep,
                    'accumulated_after'    => round($accumulated, 2),
                    'created_at'           => Carbon::create($m['year'], $m['month'], 1),
                ]);
                $created++;
            }
        }

        $this->command?->info("[Asset Depreciation] Created {$created} depreciation entries across " . min(20, $assets->count()) . ' assets.');
    }

    /* ===================================================================
     * 22. INSPECTION SPECS + MEASUREMENTS
     * =================================================================== */
    private function seedInspectionSpecs(): void
    {
        $products = DB::table('products')->limit(3)->get();
        if ($products->isEmpty()) {
            $this->command?->warn('[Inspection Specs] No products, skipping.');
            return;
        }

        // Define standard inspection parameters
        $specParams = [
            ['name' => 'Length',           'type' => 'dimensional', 'uom' => 'mm',  'nominal' => 100,   'tol_min' => -0.5, 'tol_max' => 0.5, 'critical' => true],
            ['name' => 'Width',            'type' => 'dimensional', 'uom' => 'mm',  'nominal' => 50,    'tol_min' => -0.3, 'tol_max' => 0.3, 'critical' => true],
            ['name' => 'Height',           'type' => 'dimensional', 'uom' => 'mm',  'nominal' => 30,    'tol_min' => -0.2, 'tol_max' => 0.2, 'critical' => false],
            ['name' => 'Weight',           'type' => 'weight',      'uom' => 'g',   'nominal' => 200,   'tol_min' => -5,   'tol_max' => 5,   'critical' => false],
            ['name' => 'Surface Finish',   'type' => 'visual',      'uom' => null,  'nominal' => null,  'tol_min' => null, 'tol_max' => null, 'critical' => false],
        ];

        $createdSpecs = 0;
        $createdItems = 0;

        foreach ($products as $product) {
            $specId = DB::table('inspection_specs')->insertGetId([
                'product_id' => $product->id,
                'version'    => 1,
                'is_active'  => true,
                'notes'      => "Standard inspection spec for {$product->name}",
                'created_by' => $this->admin->id,
            ]);
            $createdSpecs++;

            foreach ($specParams as $i => $p) {
                DB::table('inspection_spec_items')->insert([
                    'inspection_spec_id' => $specId,
                    'parameter_name'     => $p['name'],
                    'parameter_type'     => $p['type'],
                    'unit_of_measure'    => $p['uom'],
                    'nominal_value'      => $p['nominal'],
                    'tolerance_min'      => $p['tol_min'],
                    'tolerance_max'      => $p['tol_max'],
                    'is_critical'        => $p['critical'],
                    'sort_order'         => $i + 1,
                    'notes'              => null,
                ]);
                $createdItems++;
            }
        }

        // Now add measurements to existing inspections
        $inspections = DB::table('inspections')->get();
        $createdMeasurements = 0;

        foreach ($inspections as $inspection) {
            $product = DB::table('products')->where('id', $inspection->product_id)->first();
            if (!$product) continue;

            // Find matching spec
            $spec = DB::table('inspection_specs')->where('product_id', $inspection->product_id)->first();
            $specItems = $spec
                ? DB::table('inspection_spec_items')->where('inspection_spec_id', $spec->id)->get()
                : collect();

            $sampleCount = (int) ($inspection->sample_size ?? 10);
            $sampleSize = min(3, $sampleCount);

            for ($s = 1; $s <= $sampleSize; $s++) {
                foreach ($specItems as $si) {
                    $nominal = (float) ($si->nominal_value ?? 0);
                    $tolMin = (float) ($si->tolerance_min ?? -0.5);
                    $tolMax = (float) ($si->tolerance_max ?? 0.5);
                    $measuredVal = $nominal + $tolMin + (float)rand(0, 100) / 100 * ($tolMax - $tolMin);
                    $isPass = $measuredVal >= $nominal + $tolMin && $measuredVal <= $nominal + $tolMax;

                    DB::table('inspection_measurements')->insert([
                        'inspection_id'        => $inspection->id,
                        'inspection_spec_item_id' => $si->id ?? null,
                        'sample_index'         => $s,
                        'parameter_name'       => $si->parameter_name,
                        'parameter_type'       => $si->parameter_type,
                        'unit_of_measure'      => $si->unit_of_measure,
                        'nominal_value'        => $si->nominal_value,
                        'tolerance_min'        => $si->tolerance_min,
                        'tolerance_max'        => $si->tolerance_max,
                        'measured_value'       => round($measuredVal, 2),
                        'is_critical'          => $si->is_critical,
                        'is_pass'              => $isPass,
                        'notes'                => null,
                    ]);
                    $createdMeasurements++;
                }
            }
        }

        $this->command?->info("[Inspection Specs] Created {$createdSpecs} specs with {$createdItems} items.");
        $this->command?->info("[Inspection Measurements] Created {$createdMeasurements} measurement records.");
    }

    /* ===================================================================
     * 23. DELIVERY ITEMS
     * =================================================================== */
    private function seedDeliveryItems(): void
    {
        $deliveries = DB::table('deliveries')->get();
        $salesOrderItems = DB::table('sales_order_items')->limit(4)->get();

        if ($deliveries->isEmpty() || $salesOrderItems->isEmpty()) {
            $this->command?->warn('[Delivery Items] No deliveries or sales order items, skipping.');
            return;
        }

        $created = 0;
        foreach ($deliveries as $delivery) {
            foreach ($salesOrderItems as $i => $soi) {
                DB::table('delivery_items')->insert([
                    'delivery_id'         => $delivery->id,
                    'sales_order_item_id' => $soi->id,
                    'quantity'            => 10 + $i * 5,
                    'unit_price'          => (float) ($soi->unit_price ?? 100),
                ]);
                $created++;
            }
        }

        $this->command?->info("[Delivery Items] Created {$created} delivery item records.");
    }

    /* ===================================================================
     * 24. SHIPMENTS
     * =================================================================== */
    private function seedShipments(): void
    {
        $po = DB::table('purchase_orders')->where('status', 'approved')->first();
        if (!$po) {
            $this->command?->warn('[Shipments] No approved purchase orders, skipping.');
            return;
        }

        $now = Carbon::now();
        $shipmentData = [
            [
                'number'   => 'SHP-' . $now->format('Ymd') . '-0001',
                'status'   => 'cleared',
                'carrier'  => 'Maersk Line',
                'vessel'   => 'MSC Emma',
                'container' => 'MSCU4820137',
                'bl'       => 'MSCPEN123456',
                'etd'      => $now->copy()->subDays(30)->toDateString(),
                'atd'      => $now->copy()->subDays(28)->toDateString(),
                'eta'      => $now->copy()->subDays(15)->toDateString(),
                'ata'      => $now->copy()->subDays(12)->toDateString(),
                'customs'  => $now->copy()->subDays(10)->toDateString(),
                'notes'    => 'Container shipment from China supplier — all items accounted for',
            ],
            [
                'number'   => 'SHP-' . $now->format('Ymd') . '-0002',
                'status'   => 'in_transit',
                'carrier'  => 'APL Logistics',
                'vessel'   => 'CMA CGM Jacques Saade',
                'container' => 'CMAU5219846',
                'bl'       => 'CMACGM789012',
                'etd'      => $now->copy()->subDays(7)->toDateString(),
                'atd'      => $now->copy()->subDays(5)->toDateString(),
                'eta'      => $now->copy()->addDays(10)->toDateString(),
                'ata'      => null,
                'customs'  => null,
                'notes'    => 'Air freight — expedited shipment for critical components',
            ],
        ];

        foreach ($shipmentData as $sd) {
            DB::table('shipments')->insert([
                'shipment_number'       => $sd['number'],
                'purchase_order_id'     => $po->id,
                'status'                => $sd['status'],
                'carrier'               => $sd['carrier'],
                'vessel'                => $sd['vessel'],
                'container_number'      => $sd['container'],
                'bl_number'             => $sd['bl'],
                'etd'                   => $sd['etd'],
                'atd'                   => $sd['atd'],
                'eta'                   => $sd['eta'],
                'ata'                   => $sd['ata'],
                'customs_clearance_date' => $sd['customs'],
                'notes'                 => $sd['notes'],
                'created_by'            => $this->admin->id,
            ]);
        }

        $this->command?->info('[Shipments] Created 2 demo inbound shipments.');
    }
}
