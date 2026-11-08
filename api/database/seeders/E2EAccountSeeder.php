<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Common\Support\E2EEnvironment;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class E2EAccountSeeder extends Seeder
{
    /** @var list<array{actor:string,realm:string,email:string,role?:string}> */
    private array $accounts = [];

    public function run(): void
    {
        E2EEnvironment::assertEnabled();
        $password = (string) config('app.e2e.account_password');
        $domain = (string) config('app.e2e.account_domain');
        if (strlen($password) < 24 || ! preg_match('/^[a-z0-9.-]+\.test$/i', $domain)) {
            throw new RuntimeException('E2E account credentials must be private and use a reserved .test domain.');
        }

        $templatesByRole = collect(DemoAccountSeeder::ACCOUNTS)->keyBy('role');
        $roles = Role::query()->orderBy('slug')->get();
        foreach ($roles as $role) {
            $template = $templatesByRole->get($role->slug);
            if ($template === null) {
                throw new RuntimeException("E2E actor baseline is missing a department mapping for role {$role->slug}.");
            }

            $this->seedInternalActor(
                actor: $role->slug,
                role: $role,
                departmentCode: $template['dept'],
                password: $password,
                markDepartmentHead: $role->slug === 'department_head',
            );
        }

        // Keep ordinary E2E employee limits representative while providing a
        // separate reference actor for the company-loan VP threshold boundary.
        $this->seedInternalActor(
            actor: 'employee__loan_threshold',
            role: $roles->firstWhere('slug', 'employee') ?? throw new RuntimeException('Missing employee role.'),
            departmentCode: 'PROD',
            password: $password,
            monthlySalary: 60000,
        );

        $this->seedInternalActor(
            actor: 'purchasing_officer__2',
            role: $roles->firstWhere('slug', 'purchasing_officer') ?? throw new RuntimeException('Missing purchasing_officer role.'),
            departmentCode: 'PUR',
            password: $password,
        );
        $this->seedInternalActor(
            actor: 'finance_officer__2',
            role: $roles->firstWhere('slug', 'finance_officer') ?? throw new RuntimeException('Missing finance_officer role.'),
            departmentCode: 'FIN',
            password: $password,
        );
        $warehouseStaffRole = $roles->firstWhere('slug', 'warehouse_staff') ?? throw new RuntimeException('Missing warehouse_staff role.');
        foreach ([2, 3] as $warehouseActorNumber) {
            $this->seedInternalActor(
                actor: 'warehouse_staff__'.$warehouseActorNumber,
                role: $warehouseStaffRole,
                departmentCode: 'WH',
                password: $password,
            );
        }

        $departmentHead = $roles->firstWhere('slug', 'department_head');
        if ($departmentHead !== null) {
            foreach (Department::query()->where('is_active', true)->orderBy('code')->get() as $department) {
                if ($department->code === 'PROD') {
                    continue;
                }
                $this->seedInternalActor(
                    actor: 'department_head__'.strtolower($department->code),
                    role: $departmentHead,
                    departmentCode: $department->code,
                    password: $password,
                    markDepartmentHead: true,
                );
            }
        }

        $this->seedPortalActors($password, $domain);
        $this->call(E2EReferenceDataSeeder::class);
        if (! DB::table('settings')->where('key', 'inventory.adjustment_approval_threshold')->exists()) {
            throw new RuntimeException('E2E baseline could not set the inventory adjustment approval threshold.');
        }
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 25000);

        $manifestPath = storage_path('app/e2e/accounts.json');
        File::ensureDirectoryExists(dirname($manifestPath));
        File::put($manifestPath, json_encode([
            'accounts' => $this->accounts,
            'generated_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        @chmod($manifestPath, 0600);

        $this->command?->info('Seeded '.count($this->accounts).' isolated E2E actors; no transactional demo records were created.');
    }

    private function seedInternalActor(
        string $actor,
        Role $role,
        string $departmentCode,
        string $password,
        bool $markDepartmentHead = false,
        int $monthlySalary = 30000,
    ): void {
        $email = $this->email($actor);
        $department = Department::query()->where('code', $departmentCode)->firstOrFail();
        $position = Position::query()->where('department_id', $department->id)->orderBy('id')->firstOrFail();
        $name = 'E2E '.str_replace('_', ' ', $actor);
        $employee = Employee::query()->updateOrCreate(
            ['email' => $email],
            [
                'employee_no' => 'E2E-'.strtoupper(substr(hash('sha256', $actor), 0, 15)),
                'first_name' => 'E2E',
                'last_name' => str_replace('_', ' ', $actor),
                'birth_date' => now()->subYears(30)->toDateString(),
                'gender' => 'male',
                'civil_status' => 'single',
                'nationality' => 'Filipino',
                'email' => $email,
                'department_id' => $department->id,
                'position_id' => $position->id,
                'employment_type' => 'regular',
                'pay_type' => 'monthly',
                'date_hired' => now()->subYears(2)->toDateString(),
                'date_regularized' => now()->subYear()->toDateString(),
                'basic_monthly_salary' => $monthlySalary,
            ],
        );

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'role_id' => $role->id,
                'employee_id' => $employee->id,
                'is_active' => true,
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'password_changed_at' => now(),
                'theme_mode' => 'system',
            ],
        );

        if ($actor === 'employee') {
            $balanceYear = now('Asia/Manila')->year;
            $createdAt = now();
            $openingBalances = DB::table('leave_types')
                ->where('is_active', true)
                ->get(['id', 'default_balance'])
                ->map(fn (object $leaveType): array => [
                    'employee_id' => $employee->id,
                    'leave_type_id' => $leaveType->id,
                    'year' => $balanceYear,
                    'total_credits' => $leaveType->default_balance,
                    'used' => 0,
                    'remaining' => $leaveType->default_balance,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ])
                ->all();

            DB::table('employee_leave_balances')->insertOrIgnore($openingBalances);
        }

        if ($markDepartmentHead) {
            $department->forceFill(['head_employee_id' => $employee->id])->save();
        }

        $this->accounts[] = ['actor' => $actor, 'realm' => 'internal', 'email' => $email, 'role' => $role->slug];
    }

    private function seedPortalActors(string $password, string $domain): void
    {
        $customer = Customer::query()->where('is_active', true)->orderBy('id')->first();
        if ($customer === null) {
            $customer = Customer::query()->create([
                'name' => 'E2E Customer Ltd.',
                'contact_person' => 'E2E Contact',
                'address' => 'E2E Test Address, Cavite',
                'payment_terms_days' => 30,
                'credit_limit' => 500000,
                'is_active' => true,
            ]);
        }

        $vendor = Vendor::query()->firstOrCreate(
            ['name' => 'E2E Supplier Ltd.'],
            [
                'contact_person' => 'E2E Supplier Contact',
                'email' => 'supplier@'.$domain,
                'address' => 'E2E Test Address, Cavite',
                'payment_terms_days' => 30,
                'is_active' => true,
            ],
        );

        // Process reference data only: keep the normal purchase-request
        // conversion journey on a qualified supplier path. Transactional
        // purchase requests and orders are still created only through the UI.
        $testResin = Item::query()->where('code', 'RM-001')->where('is_active', true)->firstOrFail();
        ApprovedSupplier::query()->updateOrCreate(
            ['item_id' => $testResin->id, 'vendor_id' => $vendor->id],
            [
                'is_preferred' => true,
                'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
                'lead_time_days' => 14,
                'last_price' => $testResin->standard_cost,
                'last_price_at' => now(),
                'supplier_item_code' => $testResin->code,
                'supplier_item_name' => $testResin->name,
                'order_uom' => $testResin->unit_of_measure,
                'base_qty_per_order_unit' => 1,
            ],
        );

        $customerActor = 'customer_portal';
        $customerEmail = $this->email($customerActor);
        CustomerPortalUser::withTrashed()->updateOrCreate(
            ['email' => $customerEmail],
            [
                'customer_id' => $customer->id,
                'name' => 'E2E Customer Portal',
                'password' => Hash::make($password),
                'is_active' => true,
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'password_changed_at' => now(),
            ],
        )->restore();
        $this->accounts[] = ['actor' => $customerActor, 'realm' => 'customer', 'email' => $customerEmail];

        $supplierActor = 'supplier_portal';
        $supplierEmail = $this->email($supplierActor);
        SupplierPortalUser::withTrashed()->updateOrCreate(
            ['email' => $supplierEmail],
            [
                'vendor_id' => $vendor->id,
                'name' => 'E2E Supplier Portal',
                'password' => Hash::make($password),
                'is_active' => true,
                'must_change_password' => false,
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'password_changed_at' => now(),
            ],
        )->restore();
        $this->accounts[] = ['actor' => $supplierActor, 'realm' => 'supplier', 'email' => $supplierEmail];
    }

    private function email(string $actor): string
    {
        return 'e2e+'.str_replace('__', '-', $actor).'@'.config('app.e2e.account_domain');
    }
}
