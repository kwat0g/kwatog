<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Jobs\SyncBudgetActuals;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncBudgetActualsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_only_posted_journal_movement_within_the_fiscal_year(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $fiscalYear = FiscalYear::factory()->create([
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $account = Account::query()->firstOrFail();
        $budget = Budget::factory()->create(['fiscal_year_id' => $fiscalYear->id]);
        $lineItem = BudgetLineItem::create([
            'budget_id' => $budget->id,
            'account_id' => $account->id,
            'actual_total' => 999,
        ]);

        $this->journalLine($account, '2026-04-01', 'posted', 150, 0);
        $this->journalLine($account, '2026-04-02', 'posted', 0, 20);
        $this->journalLine($account, '2026-05-01', 'draft', 700, 0);
        $this->journalLine($account, '2027-01-01', 'posted', 900, 0);

        (new SyncBudgetActuals($fiscalYear->id))->handle();

        $this->assertSame('130.00', $lineItem->fresh()->actual_total);
    }

    private function journalLine(Account $account, string $date, string $status, float $debit, float $credit): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-'.str_replace('-', '', $date).'-'.uniqid(),
            'date' => $date,
            'description' => 'Budget actual sync test',
            'total_debit' => $debit,
            'total_credit' => $credit,
            'status' => $status,
        ]);

        JournalEntryLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
            'line_no' => 1,
            'debit' => $debit,
            'credit' => $credit,
        ]);
    }
}
