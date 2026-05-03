<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Enums\EmployeeStatus;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Position;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAccountSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /** @var array<int, array{email:string,name:string,role:string,dept:?string}> */
    private array $accounts = [
        ['email' => 'admin@ogami.test',       'name' => 'System Administrator', 'role' => 'system_admin',       'dept' => null],
        ['email' => 'hr@ogami.test',          'name' => 'Maria Santos',         'role' => 'hr_officer',         'dept' => 'HR'],
        ['email' => 'finance@ogami.test',     'name' => 'Ana Reyes',            'role' => 'finance_officer',    'dept' => 'FIN'],
        ['email' => 'production@ogami.test',  'name' => 'Ricardo Tanaka',       'role' => 'production_manager', 'dept' => 'PROD'],
        ['email' => 'ppc@ogami.test',         'name' => 'Pedro Garcia',         'role' => 'ppc_head',           'dept' => 'PPC'],
        ['email' => 'purchasing@ogami.test',  'name' => 'Elena Cruz',           'role' => 'purchasing_officer', 'dept' => 'PUR'],
        ['email' => 'warehouse@ogami.test',   'name' => 'Carlos Mendoza',       'role' => 'warehouse_staff',    'dept' => 'WH'],
        ['email' => 'qc@ogami.test',          'name' => 'Rosa Villareal',       'role' => 'qc_inspector',       'dept' => 'QC'],
        ['email' => 'maintenance@ogami.test', 'name' => 'Juan Bautista',        'role' => 'maintenance_tech',   'dept' => 'MAINT'],
        ['email' => 'impex@ogami.test',       'name' => 'Lisa Yamamoto',        'role' => 'impex_officer',      'dept' => 'IMPEX'],
        ['email' => 'depthead@ogami.test',    'name' => 'Roberto Santos',       'role' => 'department_head',    'dept' => 'PROD'],
        ['email' => 'employee@ogami.test',    'name' => 'Manuel Cruz',          'role' => 'employee',           'dept' => 'PROD'],
    ];

    public function run(): void
    {
        $created = 0;

        foreach ($this->accounts as $index => $account) {
            $role = Role::where('slug', $account['role'])->firstOrFail();
            $employee = $account['dept'] ? $this->employeeFor($account, $index) : null;

            User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'name'                 => $account['name'],
                    'password'             => Hash::make(self::PASSWORD),
                    'role_id'              => $role->id,
                    'employee_id'          => $employee?->id,
                    'is_active'            => true,
                    'must_change_password' => false,
                    'password_changed_at'  => now(),
                    'theme_mode'           => 'system',
                ],
            );
            $created++;
        }

        $this->command?->info("Demo accounts seeded ({$created}, password: " . self::PASSWORD . ').');
    }

    /**
     * @param array{email:string,name:string,role:string,dept:?string} $account
     */
    private function employeeFor(array $account, int $index): Employee
    {
        $department = Department::where('code', $account['dept'])->firstOrFail();
        $position = Position::where('department_id', $department->id)->orderBy('id')->firstOrFail();
        [$first, $last] = explode(' ', $account['name'], 2);

        return Employee::updateOrCreate(
            ['email' => $account['email']],
            [
                'employee_no'          => 'DEMO-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                'first_name'           => $first,
                'last_name'            => $last,
                'birth_date'           => now()->subYears(30 + ($index % 12))->toDateString(),
                'gender'               => in_array($first, ['Maria', 'Ana', 'Elena', 'Rosa', 'Lisa'], true) ? 'female' : 'male',
                'civil_status'         => 'single',
                'nationality'          => 'Filipino',
                'mobile_number'        => '+63917' . str_pad((string) (1000000 + $index), 7, '0', STR_PAD_LEFT),
                'department_id'        => $department->id,
                'position_id'          => $position->id,
                'employment_type'      => 'regular',
                'pay_type'             => 'monthly',
                'date_hired'           => now()->subYears(2)->subDays($index)->toDateString(),
                'date_regularized'     => now()->subYears(1)->subDays($index)->toDateString(),
                'basic_monthly_salary' => 30000 + ($index * 1500),
                'status'               => EmployeeStatus::Active->value,
            ],
        );
    }
}
