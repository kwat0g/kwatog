<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\CreditNoteService;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\JobPosting;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Services\StockCountService;
use App\Modules\Payroll\Models\DisbursementProof;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\SupplyChain\Models\ShipmentLot;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
        $this->section('B2B portal accounts (ADV10)', fn () => $this->seedPortalAccounts());
        $this->section('stock-count freeze rehearsal (ADV8)', fn () => $this->seedStockCountRehearsal());
        $this->section('PR conversion and budget rehearsal (ADV6/9)', fn () => $this->seedProcurementRehearsal());
        $this->section('supplier-return rehearsal (ADV12)', fn () => $this->seedSupplierReturnRehearsal());
        $this->section('forecast-to-MRP opt-in (ADV11)', fn () => $this->seedForecastMrpOptIn());
        $this->section('dynamic route fixtures', fn () => $this->seedDynamicRouteFixtures());

        $this->command?->info('Golden-path demo seed complete.');
    }

    /** ADV11 — ensure the forecasting screen has one product opted into MRP. */
    private function seedForecastMrpOptIn(): void
    {
        $forecastProductIds = DemandForecast::query()->select('product_id');
        $product = Product::query()
            ->where('is_active', true)
            ->whereIn('id', $forecastProductIds)
            ->orderBy('id')
            ->first() ?? Product::query()->where('is_active', true)->orderBy('id')->first();

        if (! $product) {
            throw new \RuntimeException('An active product is required for forecast-to-MRP rehearsal.');
        }

        $product->update(['include_forecast_in_mrp' => true]);
        $this->command?->info("  {$product->part_number} forecast opted into MRP projection.");
    }


    /** Run one section and fail loudly: a partial "successful" demo seed is unsafe. */
    private function section(string $label, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->command?->error("  [$label] failed: ".$e->getMessage());
            throw $e;
        }
    }

    /** ADV10 — stable credentials for the two isolated B2B portal guards. */
    private function seedPortalAccounts(): void
    {
        $vendorId = DB::table('vendors')->where('is_active', true)->orderBy('id')->value('id')
            ?? DB::table('vendors')->orderBy('id')->value('id');
        $customerId = DB::table('customers')->orderBy('id')->value('id');
        if (! $vendorId || ! $customerId) {
            throw new \RuntimeException('A vendor and customer are required for portal demo accounts.');
        }

        $supplier = SupplierPortalUser::withTrashed()->updateOrCreate(
            ['email' => 'portal@supp.test'],
            [
                'vendor_id' => $vendorId,
                'name' => 'Taiwan Plastics Portal',
                'password' => Hash::make('password'),
                'is_active' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'password_changed_at' => now(),
            ],
        );
        $supplier->restore();
        $customer = CustomerPortalUser::withTrashed()->updateOrCreate(
            ['email' => 'portal@cust.test'],
            [
                'customer_id' => $customerId,
                'name' => 'Toyota Purchasing Portal',
                'password' => Hash::make('password'),
                'is_active' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'password_changed_at' => now(),
            ],
        );
        $customer->restore();

        $this->command?->info('  Portal accounts ready (portal@supp.test / portal@cust.test).');
    }

    /** ADV8 — a draft zone count that can be started live to demonstrate freezing. */
    private function seedStockCountRehearsal(): void
    {
        if (StockCountSession::where('title', 'Defense Demo — Zone Freeze')->exists()) {
            $this->command?->info('  Stock-count rehearsal already present.');

            return;
        }

        $zone = DB::table('warehouse_zones')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('warehouse_locations')
                ->whereColumn('warehouse_locations.zone_id', 'warehouse_zones.id'))
            ->orderBy('id')->first();
        $admin = $this->admin();
        if (! $zone || ! $admin) {
            throw new \RuntimeException('A populated warehouse zone and admin are required for stock-count rehearsal.');
        }

        app(StockCountService::class)->createSession([
            'title' => 'Defense Demo — Zone Freeze',
            'scope' => 'zone',
            'warehouse_id' => $zone->warehouse_id,
            'zone_id' => $zone->id,
        ], $admin);
        $this->command?->info('  Draft stock count ready to start from Inventory › Stock Count.');
    }

    /** ADV6/9 — stable rows for PR conversion and budget-warning acknowledgment. */
    private function seedProcurementRehearsal(): void
    {
        $requester = User::where('email', 'purchasing@ogami.test')->first() ?? $this->admin();
        $departmentId = DB::table('departments')->where('code', 'MAINT')->value('id')
            ?? DB::table('departments')->orderBy('id')->value('id');
        $itemId = DB::table('items')->where('is_active', true)->orderBy('id')->value('id');
        $vendorId = DB::table('vendors')->where('is_active', true)->orderBy('id')->value('id')
            ?? DB::table('vendors')->orderBy('id')->value('id');
        if (! $requester || ! $departmentId || ! $itemId || ! $vendorId) {
            throw new \RuntimeException('Requester, department, item, and vendor are required for PR rehearsal.');
        }

        $convert = PurchaseRequest::firstOrCreate(
            ['pr_number' => 'PR-DEMO-CONVERT'],
            [
                'requested_by' => $requester->id,
                'department_id' => $departmentId,
                'date' => now()->toDateString(),
                'reason' => 'Defense demo — convert approved PR to supplier PO',
                'priority' => 'normal',
            ],
        );
        if ($convert->wasRecentlyCreated) {
            $convert->forceFill(['status' => PurchaseRequestStatus::Approved, 'approved_at' => now()])->save();
            PurchaseRequestItem::create([
                'purchase_request_id' => $convert->id,
                'item_id' => $itemId,
                'description' => 'Defense demo replenishment line',
                'quantity' => '25.00',
                'unit' => 'pcs',
                'estimated_unit_price' => '250.00',
                'purpose' => 'PR-to-PO conversion demonstration',
                'suggested_vendor_id' => $vendorId,
            ]);
        }

        $budget = PurchaseRequest::firstOrCreate(
            ['pr_number' => 'PR-DEMO-BUDGET'],
            [
                'requested_by' => $requester->id,
                'department_id' => $departmentId,
                'date' => now()->toDateString(),
                'reason' => 'Defense demo — over-budget Finance acknowledgment',
                'priority' => 'urgent',
                'budget_warning_level' => 'critical',
                'budget_warning_message' => 'Maintenance budget is at or above 100%. Finance acknowledgment is required before approval.',
            ],
        );
        if ($budget->wasRecentlyCreated) {
            $budget->forceFill(['status' => PurchaseRequestStatus::Pending, 'submitted_at' => now()])->save();
            PurchaseRequestItem::create([
                'purchase_request_id' => $budget->id,
                'item_id' => $itemId,
                'description' => 'Emergency maintenance procurement',
                'quantity' => '10.00',
                'unit' => 'pcs',
                'estimated_unit_price' => '50000.00',
                'purpose' => 'Budget acknowledgment demonstration',
                'suggested_vendor_id' => $vendorId,
            ]);
        }

        $this->command?->info('  Approved conversion PR and critical budget-warning PR ready.');
    }

    /** ADV12b — inspected supplier RMA with exact PO/GRN/bill lineage, ready to dispose. */
    private function seedSupplierReturnRehearsal(): void
    {
        if (ReturnRequest::where('rma_number', 'RMA-DEMO-SUP-READY')->exists()) {
            $this->command?->info('  Supplier-return rehearsal already present.');

            return;
        }

        $source = DB::table('grn_items as gi')
            ->join('goods_receipt_notes as g', 'g.id', '=', 'gi.goods_receipt_note_id')
            ->join('purchase_order_items as pi', 'pi.id', '=', 'gi.purchase_order_item_id')
            ->join('purchase_orders as p', 'p.id', '=', 'pi.purchase_order_id')
            ->where('gi.quantity_accepted', '>', 0)
            ->select([
                'gi.id as grn_item_id', 'gi.item_id', 'gi.quantity_accepted',
                'g.vendor_id', 'p.id as po_id', 'pi.id as po_item_id',
                'pi.unit', 'pi.unit_price',
            ])->orderBy('gi.id')->first();
        $admin = $this->admin();
        $expense = Account::where('type', 'expense')->orderBy('id')->first();
        if (! $source || ! $admin || ! $expense) {
            throw new \RuntimeException('Accepted GRN lineage, admin, and expense account are required for supplier-return rehearsal.');
        }

        $accepted = (float) $source->quantity_accepted;
        $returnQty = number_format(min(2.0, $accepted), 3, '.', '');
        $unitPrice = number_format(max((float) $source->unit_price, 1.0), 2, '.', '');
        $billSubtotal = number_format($accepted * (float) $unitPrice, 2, '.', '');

        $bill = Bill::firstOrCreate(
            ['bill_number' => 'BILL-DEMO-SUP-001'],
            [
                'vendor_id' => $source->vendor_id,
                'purchase_order_id' => $source->po_id,
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'is_vatable' => false,
                'subtotal' => $billSubtotal,
                'vat_amount' => '0.00',
                'total_amount' => $billSubtotal,
                'amount_paid' => '0.00',
                'balance' => $billSubtotal,
                'status' => 'unpaid',
                'created_by' => $admin->id,
                'remarks' => 'Defense demo supplier-return source bill',
            ],
        );
        $billItem = BillItem::firstOrCreate(
            ['bill_id' => $bill->id, 'item_id' => $source->item_id],
            [
                'expense_account_id' => $expense->id,
                'description' => 'Defense demo received material',
                'quantity' => $accepted,
                'unit' => $source->unit,
                'unit_price' => $unitPrice,
                'total' => $billSubtotal,
            ],
        );

        $rma = ReturnRequest::create([
            'rma_number' => 'RMA-DEMO-SUP-READY',
            'type' => ReturnRequestType::SupplierReturn->value,
            'status' => ReturnRequestStatus::Inspected->value,
            'purchase_order_id' => $source->po_id,
            'bill_id' => $bill->id,
            'vendor_id' => $source->vendor_id,
            'reason_code' => 'quality_issue',
            'reason_description' => 'Defense demo — incoming material failed QC.',
            'return_date' => now()->toDateString(),
            'inspected_at' => now(),
            'created_by' => $admin->id,
        ]);
        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'item_id' => $source->item_id,
            'quantity' => $returnQty,
            'returned_quantity' => $returnQty,
            'unit_price' => $unitPrice,
            'total' => bcmul($returnQty, $unitPrice, 2),
            'reason' => 'Failed incoming QC',
            'condition' => 'defective',
            'source_po_item_id' => $source->po_item_id,
            'source_grn_item_id' => $source->grn_item_id,
            'source_bill_item_id' => $billItem->id,
        ]);

        $this->command?->info('  Inspected supplier RMA ready for Return to Supplier disposition.');
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
            $batch = 'BATCH-'.$date->format('Ymd').'-'.str_pad((string) ($wo->id), 4, '0', STR_PAD_LEFT);

            $wo->forceFill([
                'batch_number' => $batch,
                'material_lot_references' => [
                    [
                        'item_id' => null,
                        'item_code' => 'RESIN-ABS',
                        'item_name' => 'Resin A (ABS)',
                        'grn_number' => 'GRN-'.$date->copy()->subDays(5)->format('Ym').'-0001',
                        'material_lot_number' => 'MLOT-'.$date->copy()->subDays(5)->format('Ymd').'-01',
                        'supplier_lot_reference' => 'SL-TW-0234',
                        'quantity_used' => 150,
                    ],
                    [
                        'item_id' => null,
                        'item_code' => 'COL-BLACK',
                        'item_name' => 'Black Colorant',
                        'grn_number' => 'GRN-'.$date->copy()->subDays(6)->format('Ym').'-0002',
                        'material_lot_number' => 'MLOT-'.$date->copy()->subDays(6)->format('Ymd').'-02',
                        'supplier_lot_reference' => 'SL-CN-0891',
                        'quantity_used' => 2,
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
        $moldId = DB::table('molds')->min('id');
        $grn = DB::table('goods_receipt_notes')->orderBy('id')->first();
        $grnItem = $grn ? DB::table('grn_items')->where('goods_receipt_note_id', $grn->id)->orderBy('id')->first() : null;

        // Point the WO's material trace at the REAL GRN line, and stamp that
        // GRN line with the matching lot so a material-lot search also resolves.
        $matLot = 'MLOT-'.Carbon::parse($grn->received_date ?? now())->format('Ymd').'-01';
        $supLot = 'SL-TW-0234';
        if ($grnItem) {
            DB::table('grn_items')->where('id', $grnItem->id)->update([
                'material_lot_number' => $matLot,
                'supplier_lot_reference' => $supLot,
                'updated_at' => now(),
            ]);
        }

        $wo->forceFill([
            'machine_id' => $wo->machine_id ?? $machineId,
            'mold_id' => $wo->mold_id ?? $moldId,
            'quantity_good' => max((int) $wo->quantity_good, 9955),
            'quantity_rejected' => max((int) $wo->quantity_rejected, 45),
            'material_lot_references' => [[
                'item_id' => $grnItem->item_id ?? null,
                'item_code' => 'RESIN-ABS',
                'item_name' => 'Resin A (ABS)',
                'grn_number' => $grn->grn_number ?? null,
                'material_lot_number' => $matLot,
                'supplier_lot_reference' => $supLot,
                'quantity_used' => 150,
            ]],
        ])->save();

        // Passed outgoing QC inspection linked to the WO (trace forward leg).
        $exists = DB::table('inspections')
            ->where('entity_type', 'work_order')->where('entity_id', $wo->id)
            ->where('stage', 'outgoing')->exists();
        if (! $exists) {
            $specId = DB::table('inspection_specs')->where('product_id', $wo->product_id)->value('id');
            DB::table('inspections')->insert([
                'inspection_number' => 'QC-'.Carbon::now()->format('Ym').'-9001',
                'stage' => 'outgoing',
                'status' => 'passed',
                'product_id' => $wo->product_id,
                'inspection_spec_id' => $specId,
                'entity_type' => 'work_order',
                'entity_id' => $wo->id,
                'batch_quantity' => 10000,
                'sample_size' => 200,
                'aql_code' => 'II',
                'accept_count' => 200,
                'reject_count' => 0,
                'defect_count' => 0,
                'inspector_id' => $this->admin()?->id,
                'started_at' => Carbon::now()->subHours(2),
                'completed_at' => Carbon::now()->subHour(),
                'notes' => 'Demo — AQL 0.65 Level II outgoing inspection, accepted.',
                'created_at' => now(),
                'updated_at' => now(),
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
                'lot_number' => 'LOT-'.$date->format('Ymd').'-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'delivery_id' => $del->id,
                'customer_id' => $customerId,
                'product_id' => $productId,
                'work_order_ids' => array_slice($woIds, 0, 2),
                'quantity' => 5000,
                'lot_date' => $date->toDateString(),
                'coc_path' => null,
                'created_by' => $admin?->id,
            ]);
            $n++;
        }
        $this->command?->info("  Created {$n} shipment lots.");
    }

    /** ADV7 — proof-of-delivery record + receiver fields for delivered deliveries. */
    private function seedDeliveryProofs(): void
    {
        $admin = $this->admin();
        $n = 0;
        $imageBytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        if ($imageBytes === false) {
            throw new \RuntimeException('Unable to decode the demo proof image.');
        }

        // Any delivery that has left / arrived is a candidate; fall back to all.
        $dels = Delivery::orderBy('id')->get();

        foreach ($dels as $del) {
            $when = Carbon::parse($del->delivered_at ?? $del->scheduled_date ?? now());
            $filePath = 'delivery-proofs/signed_dr_'.$del->id.'.gif';
            DeliveryProof::updateOrCreate([
                'delivery_id' => $del->id,
                'proof_type' => 'signed_dr',
                'notes' => 'Demo — signed delivery receipt.',
            ], [
                'file_name' => 'signed_dr_'.$when->format('Ymd').'.gif',
                'file_path' => $filePath,
                'file_size' => strlen($imageBytes),
                'mime_type' => 'image/gif',
                'uploaded_by' => $admin?->id,
            ]);
            if (! Storage::disk('local')->exists($filePath)) {
                Storage::disk('local')->put($filePath, $imageBytes);
            }

            $del->forceFill([
                'receiver_name' => $del->receiver_name ?? 'Maria Santos',
                'receiver_position' => $del->receiver_position ?? 'Purchasing Officer',
                'received_at' => $del->received_at ?? $when->copy()->setTime(10, 45),
            ])->save();
            $n++;
        }
        $this->command?->info("  Created {$n} delivery proofs.");
    }

    private function seedDynamicRouteFixtures(): void
    {
        $admin = $this->admin();
        $department = Department::query()->orderBy('id')->first();
        $posting = null;
        if ($admin && $department) {
            $posting = JobPosting::withTrashed()->firstOrNew(['posting_number' => 'JOB-DEMO-001']);
            $posting->fill([
                'department_id' => $department->id,
                'title' => 'Production Quality Engineer',
                'description' => 'Support process capability, quality planning, and continuous improvement for Ogami production lines.',
                'requirements' => 'Engineering graduate with strong analytical and cross-functional communication skills.',
                'employment_type' => 'regular',
                'show_salary' => false,
                'slots' => 1,
                'posted_at' => now()->subDay(),
                'closes_at' => now()->addMonths(2),
                'created_by' => $admin->id,
            ]);
            $posting->forceFill(['status' => 'open', 'deleted_at' => null])->save();
        }

        $driver = User::query()->where('email', 'driver@ogami.test')->first();
        $delivery = Delivery::query()->where('status', 'scheduled')->orderBy('id')->first();
        if ($driver && $delivery && ! $delivery->driver_id) {
            $delivery->forceFill(['driver_id' => $driver->id])->save();
        }

        if (! $admin) {
            throw new \RuntimeException('An admin user is required for dynamic route fixtures.');
        }

        $now = now();
        $customerId = DB::table('customers')->orderBy('id')->value('id');
        $productId = DB::table('products')->orderBy('id')->value('id');
        $employeeIds = DB::table('employees')->orderBy('id')->limit(2)->pluck('id');
        $positionId = DB::table('positions')->orderBy('id')->value('id');
        $itemId = DB::table('items')->orderBy('id')->value('id');
        $locationIds = DB::table('warehouse_locations')->orderBy('id')->limit(2)->pluck('id');

        if (! $customerId || ! $productId || $employeeIds->count() < 2 || ! $positionId || ! $itemId || $locationIds->count() < 2) {
            throw new \RuntimeException('Customer, product, two employees, position, item, and two warehouse locations are required for dynamic route fixtures.');
        }

        DB::table('customer_complaints')->updateOrInsert(
            ['complaint_number' => 'CC-DEMO-001'],
            [
                'customer_id' => $customerId,
                'product_id' => $productId,
                'received_date' => $now->toDateString(),
                'severity' => 'medium',
                'status' => 'open',
                'description' => 'Defense demo complaint for the complete CRM detail workflow.',
                'affected_quantity' => 5,
                'created_by' => $admin->id,
                'assigned_to' => $admin->id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );


        if ($posting) {
            DB::table('job_applications')->updateOrInsert(
                ['application_number' => 'APP-DEMO-001'],
                [
                    'job_posting_id' => $posting->id,
                    'tracking_code' => 'DEMO000001',
                    'first_name' => 'Alex',
                    'last_name' => 'Demo',
                    'email' => 'applicant.demo@example.test',
                    'phone' => '+639170000001',
                    'resume_path' => 'recruitment/demo-resume.pdf',
                    'resume_original_name' => 'Alex_Demo_Resume.pdf',
                    'cover_letter' => 'Defense demo application record.',
                    'stage' => 'new',
                    'applied_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        DB::table('clearances')->updateOrInsert(
            ['clearance_no' => 'CLR-DEMO-001'],
            [
                'employee_id' => $employeeIds[0],
                'separation_date' => $now->copy()->addMonth()->toDateString(),
                'separation_reason' => 'resigned',
                'clearance_items' => json_encode([[
                    'department' => 'Human Resources',
                    'item_key' => 'company_id_returned',
                    'status' => 'pending',
                    'signed_by' => null,
                    'signed_at' => null,
                    'remarks' => null,
                ]], JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'initiated_by' => $admin->id,
                'remarks' => 'Defense demo separation clearance.',
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );


        DB::table('material_review_records')->updateOrInsert(
            ['mrb_number' => 'MRB-DEMO-001'],
            [
                'item_id' => $itemId,
                'quantity' => '1.000',
                'source_location_id' => $locationIds[0],
                'quarantine_location_id' => $locationIds[1],
                'status' => 'held',
                'held_by' => $admin->id,
                'held_at' => $now,
                'notes' => 'Defense demo held-material record.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('product_routings')->updateOrInsert(
            ['product_id' => $productId, 'version' => 1],
            [
                'is_active' => true,
                'total_cycle_time' => '0.00',
                'notes' => 'Defense demo routing ready for operation setup.',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        DB::table('purchase_request_templates')->updateOrInsert(
            ['name' => 'Defense Demo Monthly Supplies'],
            [
                'department_id' => $department?->id,
                'items' => json_encode([[
                    'item_id' => app('hashids')->encode($itemId),
                    'description' => 'Defense demo recurring supply',
                    'quantity' => '5',
                    'unit' => 'pcs',
                    'estimated_unit_price' => '100.00',
                ]], JSON_THROW_ON_ERROR),
                'notes' => 'Defense demo recurring purchase template.',
                'created_by' => $admin->id,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );


        DB::table('ncr_templates')->updateOrInsert(
            ['name' => 'Defense Demo Inspection Failure'],
            [
                'source' => 'inspection_fail',
                'severity' => 'medium',
                'product_id' => $productId,
                'defect_description' => 'Measured characteristic outside specification.',
                'notes' => 'Defense demo reusable NCR template.',
                'created_by' => $admin->id,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $specItem = DB::table('inspection_spec_items as isi')
            ->join('inspection_specs as s', 's.id', '=', 'isi.inspection_spec_id')
            ->select('isi.id', 's.product_id')
            ->orderBy('isi.id')
            ->first();
        // SPC control-chart tables were intentionally removed in the current
        // quality scope cut. Keep this optional fixture additive so a fresh
        // seed still completes when that archived surface is absent.
        if (! Schema::hasTable('spc_control_charts')) {
            $this->command?->info('  SPC control charts are out of scope; skipping fixture.');
            return;
        }
        if (! $specItem) {
            throw new \RuntimeException('An inspection specification item is required for the SPC route fixture.');
        }
        DB::table('spc_control_charts')->updateOrInsert(
            [
                'product_id' => $specItem->product_id,
                'spec_item_id' => $specItem->id,
                'chart_type' => 'xbar_r',
            ],
            [
                'subgroup_size' => 5,
                'limits_locked' => false,
                'limits_sample_count' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $this->command?->info('  All parameterized SPA route fixtures ready.');
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
            'payroll_period_id' => $period->id,
            'proof_type' => 'bank_confirmation',
            'file_name' => 'BDO_TransferConfirmation.pdf',
            'file_path' => 'payroll-proofs/period_'.$period->id.'_confirmation.pdf',
            'bank_name' => 'BDO Unibank',
            'transaction_reference' => 'TXN'.Carbon::now()->format('Ymd').'001',
            'disbursed_amount' => round($amount, 2),
            'disbursement_date' => Carbon::now()->toDateString(),
            'uploaded_by' => $admin?->id,
            'notes' => 'Demo — salaries transferred via BDO corporate banking.',
        ]);

        $period->forceFill([
            'disbursement_status' => 'disbursed',
            'disbursed_at' => Carbon::now(),
            'disbursed_by' => $admin?->id,
        ])->save();

        $this->command?->info("  Marked period #{$period->id} disbursed (₱".number_format($amount, 2).').');
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
            'type' => 'customer',
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'date' => Carbon::now()->toDateString(),
            'is_vatable' => true,
            'reason' => 'Demo — credit for returned units (RMA disposition: scrap).',
            'lines' => [[
                'account_id' => $account->id,
                'description' => 'Sales return — dimensional non-conformance',
                'amount' => '12500.00',
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
                'year' => 2026,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'active',
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
            ['dept' => 'MAINT', 'name' => 'Maintenance Budget',          'alloc' => 4500000,  'spentPct' => 0.98],
        ];

        $n = 0;
        foreach ($plan as $p) {
            $dept = Department::where('code', $p['dept'])->first() ?? Department::orderBy('id')->skip($n)->first();
            $spent = round($p['alloc'] * $p['spentPct'], 2);

            $budget = Budget::create([
                'fiscal_year_id' => $fyId,
                'department_id' => $dept?->id,
                'budget_type' => 'department',
                'name' => $p['name'],
                'total_allocated' => $p['alloc'],
                'total_spent' => $spent,
                'total_committed' => round($p['alloc'] * 0.05, 2),
                'status' => 'active',
                'submitted_by' => $admin?->id,
                'submitted_at' => now()->subDays(60),
                'approved_by' => $admin?->id,
                'approved_at' => now()->subDays(50),
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
                    'budget_id' => $budget->id,
                    'account_id' => $acctId,
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
