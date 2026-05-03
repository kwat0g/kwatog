<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Services\DocumentSequenceService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\PriceAgreement;
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
 * something to render across HR, Attendance, Inventory, CRM, MRP, Production,
 * Supply Chain, and CRM complaints. Idempotent: each section guards itself
 * with a count check so re-runs only add what's missing.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private const TARGET_EMPLOYEES = 5;
    private const TARGET_VENDORS   = 4;
    private const TARGET_SOS       = 5;

    public function run(): void
    {
        $this->seedEmployees();
        $this->seedVendors();
        $this->seedStockLevels();
        $this->seedAttendance();
        $this->seedSalesOrders();
        $this->seedCustomerComplaint();
    }

    /**
     * Five employees across the existing departments. Encrypted gov IDs use
     * obviously-fake values so they're easy to spot in a demo screenshot.
     */
    private function seedEmployees(): void
    {
        if (Employee::count() >= self::TARGET_EMPLOYEES) {
            $this->command?->info('Employees already seeded.');
            return;
        }

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
            if (Employee::where('employee_no', $s['no'])->exists()) continue;
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

    /** Demo vendors used by the Procure-to-Pay chain. */
    private function seedVendors(): void
    {
        if (Vendor::count() >= self::TARGET_VENDORS) {
            $this->command?->info('Vendors already seeded.');
            return;
        }

        $samples = [
            ['name' => 'Megaplast Industries Corp.',     'tin' => '111-222-333-000', 'email' => 'sales@megaplast.ph',     'phone' => '+632-8123-4567', 'terms' => 30],
            ['name' => 'Asia Pacific Polymers, Inc.',    'tin' => '111-222-444-000', 'email' => 'orders@apolymers.ph',    'phone' => '+632-8222-3344', 'terms' => 45],
            ['name' => 'Tooling Pro Manufacturing',      'tin' => '111-222-555-000', 'email' => 'hello@toolingpro.ph',    'phone' => '+632-8345-1122', 'terms' => 30],
            ['name' => 'Pacific Logistics Solutions',    'tin' => '111-222-666-000', 'email' => 'support@paclogistics.ph','phone' => '+632-8456-2244', 'terms' => 60],
        ];

        $created = 0;
        foreach ($samples as $v) {
            $existing = Vendor::where('name', $v['name'])->first();
            if ($existing) continue;
            Vendor::create([
                'name'               => $v['name'],
                'contact_person'     => 'Account Manager',
                'email'              => $v['email'],
                'phone'              => $v['phone'],
                'address'            => 'Metro Manila, Philippines',
                'tin'                => $v['tin'],
                'payment_terms_days' => $v['terms'],
                'is_active'          => true,
            ]);
            $created++;
        }

        $this->command?->info("Seeded {$created} demo vendors.");
    }

    /**
     * Stock-level seed: every item gets between 100 and 2000 units in the
     * first available raw-materials location (or finished-goods zone for FG
     * items), valued at standard_cost.
     */
    private function seedStockLevels(): void
    {
        if (StockLevel::count() > 0) {
            $this->command?->info('Stock levels already seeded.');
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
     * Five working days of attendance per active employee — populates the
     * attendance dashboard and gives the payroll calculator a real input.
     */
    private function seedAttendance(): void
    {
        if (Attendance::count() > 0) {
            $this->command?->info('Attendance already seeded.');
            return;
        }

        $employees = Employee::where('status', EmployeeStatus::Active->value)->get();
        if ($employees->isEmpty()) {
            $this->command?->warn('No active employees; skipping attendance.');
            return;
        }

        $created = 0;
        $cursor = Carbon::today()->subDays(7);
        for ($d = 0; $d < 5; $d++) {
            // Skip weekends to mimic a normal work week.
            while ($cursor->isWeekend()) $cursor->addDay();
            $date = $cursor->copy();

            foreach ($employees as $emp) {
                Attendance::create([
                    'employee_id'       => $emp->id,
                    'date'              => $date->toDateString(),
                    'shift_id'          => null,
                    'time_in'           => $date->copy()->setTime(8, 0)->toDateTimeString(),
                    'time_out'          => $date->copy()->setTime(17, 0)->toDateTimeString(),
                    'regular_hours'     => 8.0,
                    'overtime_hours'    => 0,
                    'night_diff_hours'  => 0,
                    'tardiness_minutes' => 0,
                    'undertime_minutes' => 0,
                    'is_rest_day'       => false,
                    'status'            => AttendanceStatus::Present->value,
                    'is_manual_entry'   => true,
                ]);
                $created++;
            }
            $cursor->addDay();
        }

        $this->command?->info("Seeded {$created} attendance records (5 days × " . $employees->count() . ' employees).');
    }

    /**
     * Up to TARGET_SOS sales orders. Confirms the first three so they
     * trigger the MRP run and produce visible draft work orders. The rest
     * stay draft so the UI has both states represented.
     */
    private function seedSalesOrders(): void
    {
        if (SalesOrder::count() >= self::TARGET_SOS) {
            $this->command?->info('Sales orders already seeded.');
            return;
        }

        $admin = User::where('email', 'admin@ogami.test')->first() ?? User::first();
        if (! $admin) {
            $this->command?->warn('No admin user; skipping sales orders.');
            return;
        }

        // Pull the customer × product price-agreement matrix so we never hit
        // NoPriceAgreementException. Each tuple is a guaranteed valid line.
        // PriceAgreement is gated by effective_from/effective_to (no is_active
        // column). Pull all rows whose window covers today.
        $today = Carbon::today()->toDateString();
        $tuples = PriceAgreement::query()
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->with(['customer:id,name,is_active,payment_terms_days', 'product:id,name,is_active'])
            ->get()
            ->filter(fn ($pa) => $pa->customer && $pa->product
                && $pa->customer->is_active && $pa->product->is_active)
            ->values();

        if ($tuples->isEmpty()) {
            $this->command?->warn('No active price agreements; skipping sales orders.');
            return;
        }

        /** @var SalesOrderService $svc */
        $svc = app(SalesOrderService::class);
        $existing = SalesOrder::count();
        $needed   = self::TARGET_SOS - $existing;
        $confirmed = 0;
        $createdCount = 0;

        for ($i = 0; $i < $needed; $i++) {
            $pa  = $tuples[($existing + $i) % $tuples->count()];
            $qty = 100 + 50 * (($existing + $i) % 8); // 100, 150, 200, ...

            try {
                $so = $svc->create([
                    'customer_id' => $pa->customer_id,
                    'date'        => Carbon::today()->subDays(($existing + $i) * 2)->toDateString(),
                    'items'       => [[
                        'product_id'    => $pa->product_id,
                        'quantity'      => $qty,
                        'delivery_date' => Carbon::today()->addDays(7 + $i * 3)->toDateString(),
                    ]],
                    'payment_terms_days' => $pa->customer->payment_terms_days ?? 30,
                    'delivery_terms'     => 'Ex-Works (Cavite)',
                    'notes'              => 'Demo seed — order #' . ($existing + $i + 1),
                ], $admin->id);
                $createdCount++;

                // Confirm the first three so MRP runs and we get visible WOs.
                if ($confirmed < 3) {
                    try {
                        $svc->confirm($so);
                        $confirmed++;
                    } catch (Throwable $e) {
                        $this->command?->warn("  Confirm failed on {$so->so_number}: " . $e->getMessage());
                    }
                }
            } catch (Throwable $e) {
                $this->command?->warn('  SO create failed: ' . $e->getMessage());
            }
        }

        $this->command?->info("Seeded {$createdCount} sales orders ({$confirmed} confirmed → MRP).");
    }

    /**
     * One open customer complaint linked to the first available SO.
     * Inserted directly to skip the auto-NCR side-effect of ComplaintService —
     * the demo just needs a row visible in the complaints list to showcase
     * the 8D workflow.
     */
    private function seedCustomerComplaint(): void
    {
        if (CustomerComplaint::count() > 0) {
            $this->command?->info('Customer complaints already seeded.');
            return;
        }

        $admin    = User::where('email', 'admin@ogami.test')->first() ?? User::first();
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
