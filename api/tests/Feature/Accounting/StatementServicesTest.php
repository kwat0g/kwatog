<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\Statements\IncomeStatementService;
use App\Modules\Accounting\Services\Statements\TrialBalanceService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatementServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    public function test_trial_balance_reconciles_after_two_posted_entries(): void
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        $u = User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => $roleId,
        ]);
        $svc = app(JournalEntryService::class);

        $cashId    = (int) Account::query()->where('code','1020')->value('id');
        $capitalId = (int) Account::query()->where('code','3010')->value('id');
        $salesId   = (int) Account::query()->where('code','4010')->value('id');

        // 1) Capital infusion: DR Cash 100,000 / CR Capital Stock 100,000
        $je = $svc->create([
            'date' => '2026-04-01',
            'description' => 'Capital infusion',
            'lines' => [
                ['account_id' => Account::find($cashId)->hash_id,    'debit' => '100000.00', 'credit' => '0'],
                ['account_id' => Account::find($capitalId)->hash_id, 'debit' => '0',         'credit' => '100000.00'],
            ],
        ], $u);
        $svc->post($je, $u);

        // 2) Sale: DR Cash 5,000 / CR Sales Revenue 5,000
        $je2 = $svc->create([
            'date' => '2026-04-10',
            'description' => 'Cash sale',
            'lines' => [
                ['account_id' => Account::find($cashId)->hash_id,  'debit' => '5000.00', 'credit' => '0'],
                ['account_id' => Account::find($salesId)->hash_id, 'debit' => '0',       'credit' => '5000.00'],
            ],
        ], $u);
        $svc->post($je2, $u);

        $tb = app(TrialBalanceService::class)->generate(
            Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'),
        );

        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit'], 'Trial balance must reconcile');
        // Cash should appear with debit balance 105,000.
        $cashRow = collect($tb['accounts'])->firstWhere('code', '1020');
        $this->assertNotNull($cashRow);
        $this->assertSame('105000.00', $cashRow['debit_total']);
        $this->assertSame('0.00',      $cashRow['credit_total']);

        // Income statement net income = 5,000 (sales only, no expenses).
        $is = app(IncomeStatementService::class)->generate(
            Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'),
        );
        $this->assertSame('5000.00', $is['revenue']['total']);
        $this->assertSame('5000.00', $is['net_income']);
    }
}
