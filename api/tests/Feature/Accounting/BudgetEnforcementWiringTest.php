<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\HR\Models\Department;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetEnforcementWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    public function test_po_creation_blocked_when_budget_exhausted(): void
    {
        $dept = Department::factory()->create();
        $fy   = FiscalYear::factory()->create(['status' => 'active']);
        Budget::factory()->create([
            'department_id'   => $dept->id,
            'fiscal_year_id'  => $fy->id,
            'total_allocated' => 100.00,
            'total_spent'     => 100.00,
            'total_committed' => 0.00,
            'status'          => 'approved',
        ]);
        $user = $this->makeAdmin();
        $pr   = PurchaseRequest::factory()->create(['department_id' => $dept->id]);

        $this->actingAs($user)
            ->postJson('/api/v1/purchasing/purchase-orders', [
                'vendor_id'   => \App\Modules\Accounting\Models\Vendor::factory()->create()->hash_id,
                'date'        => now()->toDateString(),
                'purchase_request_id' => $pr->hash_id,
                'items'       => [
                    ['item_id' => \App\Modules\Inventory\Models\Item::factory()->create()->hash_id,
                     'quantity' => 1, 'unit_price' => 500.00, 'description' => 'test'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.budget.0', fn ($v) => str_contains($v, 'exhausted') || str_contains($v, 'Insufficient'));
    }

    public function test_po_creation_succeeds_within_budget(): void
    {
        $dept = Department::factory()->create();
        $fy   = FiscalYear::factory()->create(['status' => 'active']);
        Budget::factory()->create([
            'department_id'   => $dept->id,
            'fiscal_year_id'  => $fy->id,
            'total_allocated' => 10000.00,
            'total_spent'     => 0.00,
            'total_committed' => 0.00,
            'status'          => 'approved',
        ]);
        $user = $this->makeAdmin();
        $pr   = PurchaseRequest::factory()->create(['department_id' => $dept->id]);

        $this->actingAs($user)
            ->postJson('/api/v1/purchasing/purchase-orders', [
                'vendor_id'           => \App\Modules\Accounting\Models\Vendor::factory()->create()->hash_id,
                'date'                => now()->toDateString(),
                'purchase_request_id' => $pr->hash_id,
                'items'               => [
                    ['item_id' => \App\Modules\Inventory\Models\Item::factory()->create()->hash_id,
                     'quantity' => 1, 'unit_price' => 500.00, 'description' => 'test'],
                ],
            ])
            ->assertStatus(201);
    }

    public function test_bill_creation_blocked_when_budget_exhausted(): void
    {
        $dept = Department::factory()->create();
        $fy   = FiscalYear::factory()->create(['status' => 'active']);
        Budget::factory()->create([
            'department_id'   => $dept->id,
            'fiscal_year_id'  => $fy->id,
            'total_allocated' => 100.00,
            'total_spent'     => 100.00,
            'total_committed' => 0.00,
            'status'          => 'approved',
        ]);

        $user   = $this->makeAdmin();
        $vendor = \App\Modules\Accounting\Models\Vendor::factory()->create();

        // Create a minimal expense account directly (no AccountFactory exists).
        // code column is varchar(20); use a short suffix to stay within limit.
        $expenseAccount = Account::create([
            'code'           => 'TX-' . substr(uniqid(), -6),
            'name'           => 'Test Expense Account',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'is_active'      => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/v1/bills', [
                'bill_number'   => 'BILL-TEST-' . uniqid(),
                'vendor_id'     => $vendor->hash_id,
                'date'          => now()->toDateString(),
                'due_date'      => now()->addDays(30)->toDateString(),
                'department_id' => $dept->hash_id,
                'items'         => [
                    [
                        'description'        => 'Office supplies',
                        'quantity'           => 1,
                        'unit_price'         => 500.00,
                        'expense_account_id' => $expenseAccount->hash_id,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.budget.0', fn ($v) => str_contains($v, 'exhausted') || str_contains($v, 'Insufficient'));
    }
}
