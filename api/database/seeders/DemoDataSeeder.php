<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Quality\Enums\NcrSeverity;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Demo transactional data — populates the system end-to-end so the UI has
 * something to render across HR, Inventory, CRM, MRP, Production, and CRM
 * complaints. Idempotent: re-running is a no-op once seeded.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Or include it in DatabaseSeeder for a fully populated environment.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedEmployees();
        $this->seedStockLevels();
        $this->seedSalesOrder();
        $this->seedCustomerComplaint();
    }

    /**
     * Five employees across the existing departments. Encrypted gov IDs use
     * obviously-fake values so they're easy to spot in a demo screenshot.
     */
    private function seedEmployees(): void
    {
        if (Employee::count() > 0) {
            $this->command?->info('Employees already exist, skipping.');
            return;
        }

        // Pick the first position inside each requested department by code.
        $resolve = function (string $deptCode): ?array {
            $dept = Department::where('code', $deptCode)->first();
            if (! $dept) return null;
            $pos = Position::where('department_id', $dept->id)->orderBy('id')->first();
            if (! $pos) return null;
            return ['dept' => $dept->id, 'pos' => $pos->id];
        };

        $samples = [
            ['no' => 'EMP-0001', 'first' => 'Maria',  'last' => 'Santos',    'gender' => 'female', 'dept' => 'HR',   'salary' => 65000, 'pay' => 'monthly', 'hired' => '2023-01-15'],
            ['no' => 'EMP-0002', 'first' => 'Juan',   'last' => 'Dela Cruz', 'gender' => 'male',   'dept' => 'PROD', 'salary' => null,  'pay' => 'daily',   'hired' => '2024-03-10', 'daily' => 750],
            ['no' => 'EMP-0003', 'first' => 'Ana',    'last' => 'Reyes',     'gender' => 'female', 'dept' => 'QC',   'salary' => 32000, 'pay' => 'monthly', 'hired' => '2023-08-01'],
            ['no' => 'EMP-0004', 'first' => 'Pedro',  'last' => 'Garcia',    'gender' => 'male',   'dept' => 'WH',   'salary' => null,  'pay' => 'daily',   'hired' => '2024-06-20', 'daily' => 700],
            ['no' => 'EMP-0005', 'first' => 'Liza',   'last' => 'Mendoza',   'gender' => 'female', 'dept' => 'FIN',  'salary' => 38000, 'pay' => 'monthly', 'hired' => '2024-01-05'],
        ];

        $created = 0;
        foreach ($samples as $i => $s) {
            $ids = $resolve($s['dept']);
            if (! $ids) continue;

            Employee::create([
                'employee_no'          => $s['no'],
                'first_name'           => $s['first'],
                'last_name'            => $s['last'],
                'birth_date'           => Carbon::parse($s['hired'])->subYears(28)->toDateString(),
                'gender'               => $s['gender'],
                'civil_status'         => 'single',
                'nationality'          => 'Filipino',
                'mobile_number'        => '+639' . str_pad((string) (170000000 + $i), 9, '0', STR_PAD_LEFT),
                'email'                => strtolower($s['first']) . '.' . strtolower(str_replace(' ', '', $s['last'])) . '@demo.local',
                'sss_no'               => '34-' . str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT) . '-1',
                'philhealth_no'        => '12-' . str_pad((string) (100000000 + $i), 9, '0', STR_PAD_LEFT) . '-2',
                'pagibig_no'           => '1234-5678-' . str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT),
                'tin'                  => '123-456-' . str_pad((string) (700 + $i), 3, '0', STR_PAD_LEFT) . '-000',
                'department_id'        => $ids['dept'],
                'position_id'          => $ids['pos'],
                'employment_type'      => $s['pay'] === 'monthly' ? 'regular' : 'contractual',
                'pay_type'             => $s['pay'],
                'date_hired'           => $s['hired'],
                'date_regularized'     => $s['pay'] === 'monthly' ? Carbon::parse($s['hired'])->addMonths(6)->toDateString() : null,
                'basic_monthly_salary' => $s['salary'],
                'daily_rate'           => $s['daily'] ?? null,
                'status'               => EmployeeStatus::Active->value,
            ]);
            $created++;
        }

        $this->command?->info("Seeded {$created} demo employees.");
    }

    /**
     * Stock-level seed: every item gets between 100 and 2000 units in the
     * first available raw-materials location, valued at standard_cost.
     */
    private function seedStockLevels(): void
    {
        if (StockLevel::count() > 0) {
            $this->command?->info('Stock levels already exist, skipping.');
            return;
        }

        $rmZone = WarehouseZone::where('zone_type', 'raw_materials')->first()
               ?? WarehouseZone::first();
        $fgZone = WarehouseZone::where('zone_type', 'finished_goods')->first() ?? $rmZone;

        if (! $rmZone) {
            $this->command?->warn('No warehouse zones; skipping stock levels.');
            return;
        }

        $rmLocs = WarehouseLocation::where('zone_id', $rmZone->id)->orderBy('id')->pluck('id')->all();
        $fgLocs = WarehouseLocation::where('zone_id', $fgZone->id)->orderBy('id')->pluck('id')->all();

        if (empty($rmLocs)) {
            $this->command?->warn('No warehouse locations; skipping stock levels.');
            return;
        }

        $created = 0;
        foreach (Item::all() as $i => $item) {
            $isFinished = (string) ($item->item_type?->value ?? $item->item_type) === 'finished_good';
            $pool       = $isFinished ? ($fgLocs ?: $rmLocs) : $rmLocs;
            $locId      = $pool[$i % count($pool)];
            $qty        = 100 + (int) (((int) $item->id * 137) % 1900); // deterministic 100..1999

            StockLevel::create([
                'item_id'           => $item->id,
                'location_id'       => $locId,
                'quantity'          => $qty,
                'reserved_quantity' => 0,
                'weighted_avg_cost' => $item->standard_cost ?? 1.0,
                'lock_version'      => 0,
            ]);
            $created++;
        }

        $this->command?->info("Seeded {$created} stock-level rows.");
    }

    /**
     * One confirmed sales order. Going through SalesOrderService::create +
     * confirm gives us a price-agreement-resolved order plus auto-generated
     * MRP plan + draft work orders (Sprint 6 chain).
     */
    private function seedSalesOrder(): void
    {
        if (SalesOrder::count() > 0) {
            $this->command?->info('Sales orders already exist, skipping.');
            return;
        }

        $admin    = User::first();
        $customer = Customer::where('is_active', true)->first();
        $product  = Product::where('is_active', true)->orderBy('id')->first();

        if (! $admin || ! $customer || ! $product) {
            $this->command?->warn('Admin user / customer / product missing; skipping demo SO.');
            return;
        }

        try {
            /** @var SalesOrderService $svc */
            $svc = app(SalesOrderService::class);
            $so = $svc->create([
                'customer_id' => $customer->id,
                'date'        => now()->toDateString(),
                'items'       => [[
                    'product_id'    => $product->id,
                    'quantity'      => 500,
                    'delivery_date' => now()->addDays(14)->toDateString(),
                ]],
                'payment_terms_days' => $customer->payment_terms_days ?? 30,
                'delivery_terms'     => 'Ex-Works (Cavite)',
                'notes'              => 'Demo seed — confirmed order showcasing the Order to Cash chain.',
            ], $admin->id);

            $svc->confirm($so);
            $this->command?->info("Seeded demo sales order {$so->so_number} (confirmed).");
        } catch (Throwable $e) {
            $this->command?->warn('Demo SO seeding skipped: ' . $e->getMessage());
            $this->command?->warn('  at ' . $e->getFile() . ':' . $e->getLine());
            // First few frames are usually enough to locate the bug.
            $this->command?->warn(implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 10)));
        }
    }

    /**
     * One open customer complaint. Inserted directly to skip the auto-NCR
     * side-effect of ComplaintService — the demo just needs a row visible
     * in the complaints list to showcase the 8D workflow.
     */
    private function seedCustomerComplaint(): void
    {
        if (CustomerComplaint::count() > 0) {
            $this->command?->info('Customer complaints already exist, skipping.');
            return;
        }

        $admin    = User::first();
        $customer = Customer::where('is_active', true)->first();
        $product  = Product::where('is_active', true)->orderBy('id')->first();

        if (! $admin || ! $customer || ! $product) {
            $this->command?->warn('Admin / customer / product missing; skipping complaint.');
            return;
        }

        DB::transaction(function () use ($admin, $customer, $product) {
            /** @var DocumentSequenceService $seq */
            $seq = app(DocumentSequenceService::class);
            $number = $seq->generate('complaint');

            CustomerComplaint::create([
                'complaint_number'  => $number,
                'customer_id'       => $customer->id,
                'product_id'        => $product->id,
                'sales_order_id'    => SalesOrder::orderBy('id')->value('id'),
                'received_date'     => now()->subDays(2)->toDateString(),
                'severity'          => NcrSeverity::High->value,
                'status'            => ComplaintStatus::Open->value,
                'description'       => 'Customer reports surface scratches on a small batch of delivered units. Demo entry for showcasing the 8D workflow.',
                'affected_quantity' => 12,
                'created_by'        => $admin->id,
            ]);
        });

        $this->command?->info('Seeded 1 demo customer complaint.');
    }
}
