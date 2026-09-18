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
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = trim((string) env('ADMIN_EMAIL', ''));
        $name = trim((string) env('ADMIN_NAME', ''));
        $password = (string) env('ADMIN_PASSWORD', '');
        if ($email === '' || $name === '' || $password === '') {
            $this->command?->warn('Admin bootstrap skipped: set ADMIN_EMAIL, ADMIN_NAME, and ADMIN_PASSWORD to create the initial administrator.');
            return;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            throw new RuntimeException('ADMIN_EMAIL must be valid and ADMIN_PASSWORD must be at least 12 characters.');
        }
        $role = Role::where('slug', 'system_admin')->firstOrFail();
        $employee = $this->employeeFor($email, $name);

        $hash = Hash::make($password);

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name'                  => $name,
                'password'              => $hash,
                'role_id'               => $role->id,
                'employee_id'           => $employee->id,
                'is_active'             => true,
                'must_change_password'  => false,
                'password_changed_at'   => now(),
                'theme_mode'            => 'system',
            ],
        );

        $this->command?->info("System Admin {$user->email} ready.");
    }

    private function employeeFor(string $email, string $name): Employee
    {
        $department = Department::updateOrCreate(
            ['code' => 'IT'],
            ['name' => 'Information Technology', 'is_active' => true],
        );
        $position = Position::firstOrCreate([
            'department_id' => $department->id,
            'title'         => 'System Administrator',
        ]);
        [$firstName, $lastName] = array_pad(explode(' ', trim($name), 2), 2, null);
        $lastName ??= $firstName;

        return Employee::updateOrCreate(
            ['email' => $email],
            [
                'employee_no'          => 'ADMIN-' . strtoupper(substr(sha1($email), 0, 10)),
                'first_name'           => $firstName,
                'last_name'            => $lastName,
                'birth_date'           => now()->subYears(35)->toDateString(),
                'gender'               => 'male',
                'civil_status'         => 'single',
                'nationality'          => 'Filipino',
                'department_id'        => $department->id,
                'position_id'          => $position->id,
                'employment_type'      => 'regular',
                'pay_type'             => 'monthly',
                'date_hired'           => now()->toDateString(),
                'basic_monthly_salary' => null,
                'status'               => EmployeeStatus::Active->value,
            ],
        );
    }
}
