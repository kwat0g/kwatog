<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingPeriodAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $role)->value('id'),
            'is_active' => true,
        ]);
    }

    public function test_finance_can_view_and_manage_periods_but_employee_cannot(): void
    {
        $finance = $this->user('finance_officer');
        $employee = $this->user('employee');

        $this->actingAs($finance)
            ->getJson('/api/v1/accounting/periods')
            ->assertOk();

        $this->actingAs($finance)
            ->postJson('/api/v1/accounting/periods/close', ['year' => 2026, 'month' => 8])
            ->assertOk();

        $this->actingAs($finance)
            ->postJson('/api/v1/accounting/periods/reopen', [
                'year' => 2026,
                'month' => 8,
                'reason' => 'Approved correction.',
            ])
            ->assertOk();

        $this->actingAs($employee)
            ->getJson('/api/v1/accounting/periods')
            ->assertForbidden();

        $this->actingAs($employee)
            ->postJson('/api/v1/accounting/periods/close', ['year' => 2026, 'month' => 9])
            ->assertForbidden();
    }
}
