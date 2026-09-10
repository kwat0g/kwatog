<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Support\Money;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountService;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Accounting\Services\Statements\BalanceSheetService;
use App\Modules\Accounting\Services\Statements\IncomeStatementService;
use App\Modules\Accounting\Services\Statements\TrialBalanceService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * AB-01 — a reversed JE must keep its historical effect: the original stays
 * attributed to its own date and only the mirror entry (a separate posted JE
 * dated at the reversal) nets it out from the reversal date forward. Windows
 * containing the original date but not the reversal date must still show it.
 */
class ReversedJournalStatementHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function admin(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        return User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => $roleId,
        ]);
    }

    private function id(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->hash_id;
    }

    private function flushStatementCache(): void
    {
        Cache::tags(['financial_statements'])->flush();
    }

    /**
     * Post DR Cash 8,000 / CR Sales 8,000 dated 2026-06-10, then reverse it
     * with a mirror entry dated 2026-08-05.
     */
    private function postJuneSaleReversedInAugust(User $user): JournalEntry
    {
        $svc = app(JournalEntryService::class);
        $je = $svc->create([
            'date' => '2026-06-10',
            'description' => 'June cash sale',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '8000.00', 'credit' => '0'],
                ['account_id' => $this->id('4010'), 'debit' => '0',       'credit' => '8000.00'],
            ],
        ], $user);
        $je = $svc->post($je, $user);
        $svc->reverse($je, $user, Carbon::parse('2026-08-05'), 'Customer order cancelled.');

        return $je->fresh();
    }

    public function test_income_statement_keeps_reversed_revenue_until_reversal_date(): void
    {
        $user = $this->admin();
        $je = $this->postJuneSaleReversedInAugust($user);
        $this->assertSame(JournalEntryStatus::Reversed, $je->status);

        $is = app(IncomeStatementService::class);

        $june = $is->generate(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));
        $this->assertSame('8000.00', $june['revenue']['total']);
        $this->assertSame('8000.00', $june['net_income']);

        // Window containing the original date but ending before the reversal:
        // the revenue must NOT vanish now that the JE carries status=reversed.
        $julyYtd = $is->generate(Carbon::parse('2026-01-01'), Carbon::parse('2026-07-31'));
        $this->assertSame('8000.00', $julyYtd['revenue']['total']);
        $this->assertSame('8000.00', $julyYtd['net_income']);

        // A window covering both legs nets to zero.
        $septYtd = $is->generate(Carbon::parse('2026-01-01'), Carbon::parse('2026-09-30'));
        $this->assertSame('0.00', $septYtd['revenue']['total']);
        $this->assertSame('0.00', $septYtd['net_income']);

        // Consecutive monthly statements sum to YTD across the reversal.
        $july = $is->generate(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
        $august = $is->generate(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        $this->assertSame('0.00', $july['revenue']['total']);
        $this->assertSame('-8000.00', $august['revenue']['total']);

        $monthlySum = Money::add(
            Money::add($june['revenue']['total'], $july['revenue']['total']),
            $august['revenue']['total'],
        );
        $augYtd = $is->generate(Carbon::parse('2026-01-01'), Carbon::parse('2026-08-31'));
        $this->assertSame($monthlySum, $augYtd['revenue']['total']);
    }

    public function test_trial_balance_includes_reversed_original_before_reversal_date(): void
    {
        $user = $this->admin();
        $this->postJuneSaleReversedInAugust($user);
        $svc = app(TrialBalanceService::class);

        // As-of window between the original and reversal dates.
        $tb = $svc->generate(Carbon::parse('2026-06-01'), Carbon::parse('2026-07-31'));
        $cash = collect($tb['accounts'])->firstWhere('code', '1010');
        $sales = collect($tb['accounts'])->firstWhere('code', '4010');
        $this->assertNotNull($cash);
        $this->assertNotNull($sales);
        $this->assertSame('8000.00', $cash['debit_total']);
        $this->assertSame('0.00', $cash['credit_total']);
        $this->assertSame('8000.00', $cash['balance']);
        $this->assertSame('8000.00', $sales['credit_total']);
        $this->assertSame($tb['totals']['debit'], $tb['totals']['credit']);

        // As-of window after the reversal: both legs net out.
        $full = $svc->generate(Carbon::parse('2026-06-01'), Carbon::parse('2026-09-30'));
        $cashFull = collect($full['accounts'])->firstWhere('code', '1010');
        $salesFull = collect($full['accounts'])->firstWhere('code', '4010');
        $this->assertSame('8000.00', $cashFull['debit_total']);
        $this->assertSame('8000.00', $cashFull['credit_total']);
        $this->assertSame('0.00', $cashFull['balance']);
        $this->assertSame('0.00', $salesFull['balance']);
        $this->assertSame($full['totals']['debit'], $full['totals']['credit']);
    }

    public function test_balance_sheet_as_of_holds_reversed_entry_until_reversal_date(): void
    {
        $user = $this->admin();
        $this->postJuneSaleReversedInAugust($user);
        $svc = app(BalanceSheetService::class);

        $before = $svc->generate(Carbon::parse('2026-07-31'));
        $this->assertTrue($before['balanced']);
        $this->assertSame('8000.00', $before['total_assets']);
        $this->assertSame('8000.00', $before['total_liabilities_equity']);

        $after = $svc->generate(Carbon::parse('2026-09-30'));
        $this->assertTrue($after['balanced']);
        $this->assertSame('0.00', $after['total_assets']);
        $this->assertSame('0.00', $after['total_liabilities_equity']);
    }

    public function test_invoice_cancellation_keeps_historical_ar_consistent_with_aging(): void
    {
        $user = $this->admin();
        $customer = Customer::create(['name' => 'Toyota PH', 'payment_terms_days' => 30]);
        $svc = app(InvoiceService::class);

        $invoice = $svc->create([
            'customer_id' => $customer->hash_id,
            'lifecycle_type' => 'prebill',
            'prebill_reason' => 'AB-01 regression fixture',
            'date' => '2026-06-05',
            'due_date' => '2026-07-05',
            'is_vatable' => false,
            'items' => [[
                'revenue_account_id' => $this->id('4010'),
                'description' => 'Wiper bushings',
                'quantity' => '10',
                'unit_price' => '1000.00',
            ]],
        ], $user);
        $invoice = $svc->finalize($invoice, $user);

        $bs = app(BalanceSheetService::class);
        $juneBs = $bs->generate(Carbon::parse('2026-06-30'));
        $juneAr = collect($juneBs['assets']['accounts'])->firstWhere('code', '1100');
        $this->assertNotNull($juneAr);
        $this->assertSame('10000.00', $juneAr['amount']);

        // Cancel after the June period end; the reversal JE is dated today.
        Carbon::setTestNow(Carbon::parse('2026-08-12 09:00:00'));
        try {
            $svc->cancel($invoice->fresh(), $user);
        } finally {
            Carbon::setTestNow();
        }
        $this->flushStatementCache();

        // Historical BS as of before the cancel date still carries the AR,
        // and agrees with AR aging at the same as-of date.
        $julyBs = $bs->generate(Carbon::parse('2026-07-31'));
        $julyAr = collect($julyBs['assets']['accounts'])->firstWhere('code', '1100');
        $this->assertNotNull($julyAr);
        $this->assertSame('10000.00', $julyAr['amount']);
        $this->assertTrue($julyBs['balanced']);
        $julyAging = $svc->aging(Carbon::parse('2026-07-31'));
        $this->assertSame('10000.00', $julyAging['buckets']['total']);

        // After the cancel date, both surfaces net the receivable out.
        $septBs = $bs->generate(Carbon::parse('2026-09-30'));
        $septAr = collect($septBs['assets']['accounts'])->firstWhere('code', '1100');
        $this->assertNotNull($septAr);
        $this->assertSame('0.00', $septAr['amount']);
        $this->assertTrue($septBs['balanced']);
        $septAging = $svc->aging(Carbon::parse('2026-09-30'));
        $this->assertSame('0.00', $septAging['buckets']['total']);
    }

    public function test_chart_of_accounts_balances_net_reversed_entries(): void
    {
        $user = $this->admin();
        $this->postJuneSaleReversedInAugust($user);

        $flatten = function (array $nodes) use (&$flatten): array {
            $out = [];
            foreach ($nodes as $node) {
                $out[] = $node;
                foreach ($flatten($node->children->all()) as $child) {
                    $out[] = $child;
                }
            }
            return $out;
        };
        $byCode = collect($flatten(app(AccountService::class)->tree()))->keyBy('code');

        $this->assertSame('0.00', $byCode['1010']->current_balance);
        $this->assertSame('8000.00', $byCode['1010']->total_debit);
        $this->assertSame('8000.00', $byCode['1010']->total_credit);
        $this->assertSame('0.00', $byCode['4010']->current_balance);
    }

    public function test_budget_posted_actuals_net_reversed_entries(): void
    {
        $user = $this->admin();
        $fiscalYear = FiscalYear::factory()->create([
            'status' => 'active',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $department = Department::factory()->create();
        $expense = Account::create([
            'code' => 'B-'.substr(uniqid(), -6),
            'name' => 'Budget test expense',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        $budget = Budget::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'department_id' => $department->id,
            'status' => 'active',
            'total_allocated' => '100.00',
            'total_spent' => '0.00',
        ]);
        BudgetLineItem::create(['budget_id' => $budget->id, 'account_id' => $expense->id, 'jan' => '100.00']);

        $svc = app(JournalEntryService::class);
        $je = $svc->create([
            'date' => '2026-04-01',
            'description' => 'Budget expense',
            'lines' => [
                ['account_id' => $expense->hash_id,  'debit' => '40.00', 'credit' => '0'],
                ['account_id' => $this->id('1010'),  'debit' => '0',     'credit' => '40.00'],
            ],
        ], $user);
        $svc->post($je, $user);

        $overview = app(BudgetService::class)->overview($fiscalYear->id);
        $this->assertSame('40.00', $overview['total_spent']);

        $svc->reverse($je, $user, Carbon::parse('2026-08-01'), 'Duplicate posting.');

        $overview = app(BudgetService::class)->overview($fiscalYear->id);
        $this->assertSame('0.00', $overview['total_spent']);
        $this->assertSame('100.00', $overview['total_available']);
    }
}
