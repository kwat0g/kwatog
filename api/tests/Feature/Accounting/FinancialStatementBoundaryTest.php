<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialStatementBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_view_only_user_can_read_json_but_cannot_export(): void
    {
        $user = $this->userWithPermissions(['accounting.statements.view']);

        $this->actingAs($user)
            ->getJson('/api/v1/accounting/statements/trial-balance?from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertJsonPath('data.currency', 'PHP');

        $this->actingAs($user)
            ->get('/api/v1/accounting/statements/trial-balance?from=2026-08-01&to=2026-08-31&format=csv')
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/api/v1/accounting/statements/trial-balance/pdf?from=2026-08-01&to=2026-08-31')
            ->assertForbidden();
    }

    public function test_export_capable_user_receives_currency_and_reconciliation_metadata(): void
    {
        $user = $this->userWithPermissions([
            'accounting.statements.view',
            'accounting.statements.export',
        ]);

        $response = $this->actingAs($user)
            ->get('/api/v1/accounting/statements/trial-balance?from=2026-08-01&to=2026-08-31&format=csv')
            ->assertOk();

        $body = $response->streamedContent();
        self::assertStringContainsString('Row Type,Currency,Code,Name,Type,Debit Total,Credit Total,Balance,Side', $body);
        self::assertStringContainsString('Total,PHP', $body);
        self::assertStringContainsString('Status,PHP,,Reconciled', $body);
    }

    public function test_statement_date_contract_rejects_malformed_and_reversed_ranges(): void
    {
        $user = $this->userWithPermissions(['accounting.statements.view']);

        $this->actingAs($user)
            ->getJson('/api/v1/accounting/statements/trial-balance?from=2026-02-31&to=2026-03-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');

        $this->actingAs($user)
            ->getJson('/api/v1/accounting/statements/trial-balance?from=2026-08-31&to=2026-08-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');

        $this->actingAs($user)
            ->getJson('/api/v1/accounting/statements/balance-sheet?as_of=08/31/2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors('as_of');

        $this->actingAs($user)
            ->getJson('/api/v1/accounting/statements/income-statement/pdf?from=2026-08-31&to=2026-08-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    private function userWithPermissions(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Statement Boundary '.uniqid(),
            'slug' => 'statement-boundary-'.uniqid(),
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id')->all());

        return User::factory()->create(['role_id' => $role->id]);
    }
}
