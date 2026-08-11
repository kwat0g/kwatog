<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class JournalEntryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function user(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        return User::create([
            'name' => 'Test', 'email' => 't_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => $roleId,
        ]);
    }

    private function id(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->hash_id;
    }

    public function test_balanced_two_line_entry_persists_with_sequential_number(): void
    {
        $user = $this->user();
        $svc = app(JournalEntryService::class);

        $je = $svc->create([
            'date' => '2026-04-15',
            'description' => 'Capital infusion',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '50000.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0',        'credit' => '50000.00'],
            ],
        ], $user);

        $this->assertNotNull($je->id);
        $this->assertSame('50000.00', (string) $je->total_debit);
        $this->assertSame('50000.00', (string) $je->total_credit);
        $this->assertSame(JournalEntryStatus::Draft, $je->status);
        $this->assertMatchesRegularExpression('/^JE-\d{6}-\d{4}$/', $je->entry_number);
        $this->assertCount(2, $je->lines);
    }

    public function test_unbalanced_entry_is_rejected(): void
    {
        $user = $this->user();
        $svc = app(JournalEntryService::class);

        $this->expectException(UnbalancedJournalEntryException::class);
        $svc->create([
            'date' => '2026-04-15',
            'description' => 'Bad entry',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0',      'credit' => '99.00'],
            ],
        ], $user);
    }

    public function test_posted_entry_can_be_reversed_once(): void
    {
        $user = $this->user();
        $svc = app(JournalEntryService::class);

        $je = $svc->create([
            'date' => '2026-04-15',
            'description' => 'Original',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '1000.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0',       'credit' => '1000.00'],
            ],
        ], $user);

        $je = $svc->post($je, $user);
        $this->assertSame(JournalEntryStatus::Posted, $je->status);

        $reversal = $svc->reverse($je, $user);
        $this->assertSame(JournalEntryStatus::Posted, $reversal->status);
        $this->assertSame(JournalEntryStatus::Reversed, $je->fresh()->status);
        $this->assertSame((int) $reversal->id, (int) $je->fresh()->reversed_by_entry_id);
        $this->assertCount(2, $reversal->lines);
        // Mirror: original DR 1010 → reversal CR 1010
        $reversedFirstLine = $reversal->lines->first();
        $this->assertTrue((float) $reversedFirstLine->credit > 0);

        // Cannot reverse twice.
        $this->expectException(\RuntimeException::class);
        $svc->reverse($je->fresh(), $user);
    }

    public function test_reverse_rejects_a_stale_posted_entry_after_it_is_reversed(): void
    {
        $user = $this->user();
        $svc  = app(JournalEntryService::class);
        $je   = $svc->create([
            'date' => '2026-04-15',
            'description' => 'Stale original',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '1000.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0',       'credit' => '1000.00'],
            ],
        ], $user);
        $je = $svc->post($je, $user);

        DB::table('journal_entries')->where('id', $je->id)->update([
            'status' => JournalEntryStatus::Reversed->value,
        ]);

        $exception = null;
        try {
            $svc->reverse($je, $user);
        } catch (RuntimeException $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception, 'A stale posted JE must not be reversed after the persisted row is reversed.');
        $this->assertSame('Only posted entries can be reversed.', $exception->getMessage());

        $row = DB::table('journal_entries')->where('id', $je->id)->first();
        $this->assertSame(JournalEntryStatus::Reversed->value, $row->status);
        $this->assertNull($row->reversed_by_entry_id);
        $this->assertSame(0, JournalEntry::query()->where('reference_type', 'journal_entry_reversal')->count());
        $this->assertSame(1, DB::table('document_sequences')->where('document_type', 'journal_entry')->count());
    }

    public function test_reverse_uses_locked_totals_and_lines_when_caller_is_stale(): void
    {
        $user = $this->user();
        $svc  = app(JournalEntryService::class);
        $je   = $svc->create([
            'date' => '2026-04-15',
            'description' => 'Authoritative original',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '1000.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0',       'credit' => '1000.00'],
            ],
        ], $user);
        $je = $svc->post($je, $user);
        $lineIds = DB::table('journal_entry_lines')->where('journal_entry_id', $je->id)->orderBy('line_no')->pluck('id');

        // Keep the posted model (and its eager-loaded lines) stale while the
        // persisted ledger facts are replaced with another balanced amount.
        DB::table('journal_entries')->where('id', $je->id)->update([
            'total_debit'  => '2500.00',
            'total_credit' => '2500.00',
        ]);
        DB::table('journal_entry_lines')->where('id', $lineIds[0])->update(['debit' => '2500.00']);
        DB::table('journal_entry_lines')->where('id', $lineIds[1])->update(['credit' => '2500.00']);

        $reversal = $svc->reverse($je, $user);

        $this->assertSame('2500.00', (string) $reversal->total_debit);
        $this->assertSame('2500.00', (string) $reversal->total_credit);
        $this->assertSame('2500.00', (string) $reversal->lines->first()->credit);
        $this->assertSame(JournalEntryStatus::Reversed, $je->fresh()->status);
    }
}
