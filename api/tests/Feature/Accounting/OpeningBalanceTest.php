<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\OpeningBalanceService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REC-05 — opening-balance loader + trial-balance reconciliation.
 */
class OpeningBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function financeUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
    }

    public function test_balanced_legacy_tb_posts_one_opening_entry_and_reconciles(): void
    {
        $svc = app(OpeningBalanceService::class);
        $cash    = Account::query()->where('code', '1020')->firstOrFail();
        $capital = Account::query()->where('code', '3010')->firstOrFail();

        $lines = [
            ['account_id' => $cash->hash_id,    'debit' => '100000.00', 'credit' => '0'],
            ['account_id' => $capital->hash_id, 'debit' => '0',         'credit' => '100000.00'],
        ];

        $je = $svc->loadGl(['date' => '2026-01-01', 'lines' => $lines], $this->financeUser());

        $this->assertInstanceOf(JournalEntry::class, $je);
        $this->assertSame('posted', $je->status->value);
        $this->assertSame(1, JournalEntry::query()->count());

        // The system TB now matches the submitted legacy TB → balanced, zero variance.
        $match = $svc->trialBalanceMatch($lines, \Carbon\Carbon::parse('2026-01-31'));
        $this->assertTrue($match['balanced'], 'System TB must equal the legacy TB after loading.');
        foreach ($match['rows'] as $row) {
            $this->assertSame('0.00', $row['variance'], "Account {$row['account_code']} must reconcile.");
        }
    }

    public function test_unbalanced_legacy_tb_is_rejected_and_creates_no_entry(): void
    {
        $svc = app(OpeningBalanceService::class);
        $cash    = Account::query()->where('code', '1020')->firstOrFail();
        $capital = Account::query()->where('code', '3010')->firstOrFail();

        $this->expectException(\RuntimeException::class);
        try {
            $svc->loadGl([
                'date' => '2026-01-01',
                'lines' => [
                    ['account_id' => $cash->hash_id,    'debit' => '100000.00', 'credit' => '0'],
                    ['account_id' => $capital->hash_id, 'debit' => '0',         'credit' => '90000.00'],
                ],
            ], $this->financeUser());
        } finally {
            // No journal entry may have been created.
            $this->assertSame(0, JournalEntry::query()->count());
        }
    }

    public function test_unbalanced_tb_via_route_returns_422(): void
    {
        $cash    = Account::query()->where('code', '1020')->firstOrFail();
        $capital = Account::query()->where('code', '3010')->firstOrFail();

        $this->actingAs($this->financeUser())
            ->postJson('/api/v1/accounting/opening-balances/gl', [
                'date' => '2026-01-01',
                'lines' => [
                    ['account_id' => $cash->hash_id,    'debit' => '100000.00', 'credit' => '0'],
                    ['account_id' => $capital->hash_id, 'debit' => '0',         'credit' => '90000.00'],
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_opening_stock_seeds_level_with_cost_basis(): void
    {
        $svc = app(OpeningBalanceService::class);
        $item = Item::factory()->create(['is_active' => true]);
        $loc  = WarehouseLocation::factory()->create();

        $result = $svc->loadStock(
            [['item_id' => $item->hash_id, 'quantity' => '100', 'unit_cost' => '25.50']],
            $loc->hash_id,
            $this->financeUser(),
        );

        $this->assertSame(1, $result['count']);
        $this->assertSame('2550.00', $result['total_value']); // 100 × 25.50

        $level = StockLevel::query()->where('item_id', $item->id)->where('location_id', $loc->id)->firstOrFail();
        $this->assertSame('100.000', (string) $level->quantity);
        $this->assertSame('25.5000', (string) $level->weighted_avg_cost);

        $this->assertSame(1, StockMovement::query()
            ->where('item_id', $item->id)
            ->where('movement_type', 'opening')
            ->count());
    }

    public function test_opening_balance_routes_are_permission_gated(): void
    {
        $employee = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);

        $this->actingAs($employee)
            ->postJson('/api/v1/accounting/opening-balances/gl', ['date' => '2026-01-01', 'lines' => []])
            ->assertStatus(403);

        $this->actingAs($employee)
            ->postJson('/api/v1/accounting/opening-balances/stock', ['location_id' => 'x', 'rows' => []])
            ->assertStatus(403);
    }
}
