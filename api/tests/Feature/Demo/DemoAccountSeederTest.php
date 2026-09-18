<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use Database\Seeders\DemoAccountSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\PositionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_internal_demo_account_is_linked_to_one_employee(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            DemoAccountSeeder::class,
        ]);

        $users = User::query()->with('employee')->get();

        $this->assertCount(18, $users);
        $this->assertCount($users->count(), $users->pluck('employee_id')->filter()->unique());
        $this->assertTrue($users->every(
            static fn (User $user): bool => $user->employee_id !== null && $user->employee !== null,
        ));
        $this->assertSame(
            $users->count(),
            Employee::query()->whereIn('id', $users->pluck('employee_id'))->count(),
        );

        $salesEmployee = User::query()->where('email', 'crm@ogami.test')->firstOrFail()->employee;
        $serviceEmployee = User::query()->where('email', 'customerservice@ogami.test')->firstOrFail()->employee;

        $this->assertSame('SALES', Department::query()->findOrFail($salesEmployee->department_id)->code);
        $this->assertSame('Sales Officer', $salesEmployee->position()->value('title'));
        $this->assertSame('CS', Department::query()->findOrFail($serviceEmployee->department_id)->code);
        $this->assertSame('Customer Service Officer', $serviceEmployee->position()->value('title'));
    }

    public function test_seeding_demo_accounts_again_reuses_the_employee_links(): void
    {
        $this->seed([
            RolePermissionSeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            DemoAccountSeeder::class,
        ]);
        $links = User::query()->pluck('employee_id', 'email')->all();
        ksort($links);

        $this->seed(DemoAccountSeeder::class);

        $currentLinks = User::query()->pluck('employee_id', 'email')->all();
        ksort($currentLinks);

        $this->assertSame($links, $currentLinks);
        $this->assertSame(18, Employee::query()->count());
    }
}
