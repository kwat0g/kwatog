<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Exceptions\ClosedPeriodException;
use App\Modules\Accounting\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Common\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Carbon\Carbon;
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
        $this->assertTrue(AuditLog::query()
            ->where('model_type', JournalEntryLine::class)
            ->where('model_id', $je->lines->first()->id)
            ->exists());
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

        $reversal = $svc->reverse($je, $user, null, 'Correction approved by finance.');
        $this->assertSame(JournalEntryStatus::Posted, $reversal->status);
        $this->assertSame('Correction approved by finance.', $reversal->reversal_reason);
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

        $replacement = JournalEntry::create([
            'entry_number' => 'JE-999999-9999',
            'date' => '2026-04-15',
            'description' => 'Existing valid reversal',
            'reference_type' => 'journal_entry_reversal',
            'reference_id' => $je->id,
            'total_debit' => '1000.00',
            'total_credit' => '1000.00',
            'status' => JournalEntryStatus::Posted,
            'posted_at' => now(),
            'posted_by' => $user->id,
        ]);

        DB::table('journal_entries')->where('id', $je->id)->update([
            'status' => JournalEntryStatus::Reversed->value,
            'reversed_by_entry_id' => $replacement->id,
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
        $this->assertSame((int) $replacement->id, (int) $row->reversed_by_entry_id);
        $this->assertSame(1, JournalEntry::query()->where('reference_type', 'journal_entry_reversal')->count());
        $this->assertSame(1, DB::table('document_sequences')->where('document_type', 'journal_entry')->count());
    }

    public function test_posted_journal_lines_cannot_be_mutated_by_raw_queries(): void
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

        $this->expectException(QueryException::class);
        DB::table('journal_entry_lines')->where('id', $lineIds[0])->update(['debit' => '2500.00']);
    }

    public function test_database_rejects_soft_deleting_a_posted_entry(): void
    {
        $user = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $svc->post($svc->create([
            'date' => '2026-04-15',
            'description' => 'Terminal archive guard',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $user), $user);

        $this->expectException(QueryException::class);
        DB::table('journal_entries')->where('id', $je->id)->update(['deleted_at' => now()]);
    }

    public function test_database_rejects_an_unlinked_reversal_transition(): void
    {
        $user = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $svc->post($svc->create([
            'date' => '2026-04-15',
            'description' => 'Invalid reversal guard',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $user), $user);

        $this->expectException(QueryException::class);
        DB::table('journal_entries')->where('id', $je->id)->update([
            'status' => JournalEntryStatus::Reversed->value,
        ]);
    }

    public function test_posting_rejects_an_empty_locked_line_aggregate(): void
    {
        $user = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $svc->create([
            'date' => '2026-04-15',
            'description' => 'Empty aggregate guard',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $user);

        DB::table('journal_entry_lines')->where('journal_entry_id', $je->id)->delete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least two lines');
        $svc->post($je, $user);
    }

    public function test_explicit_posting_actor_and_reversal_reason_are_audited_on_header_and_lines(): void
    {
        $maker = $this->user();
        $actor = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $svc->create([
            'date' => '2026-04-15',
            'description' => 'Audit attribution source',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $maker);

        $svc->postSystem($je, $actor->id);
        $postingAudit = AuditLog::query()
            ->where('model_type', JournalEntry::class)
            ->where('model_id', $je->id)
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame((int) $actor->id, (int) $postingAudit->user_id);
        $this->assertSame('user', $postingAudit->actor_type);

        $reversal = $svc->reverse($je->fresh(), $actor, null, 'Correction approved by finance.');
        $sourceAudit = AuditLog::query()
            ->where('model_type', JournalEntry::class)
            ->where('model_id', $je->id)
            ->where('action', 'updated')
            ->where('reason', 'Correction approved by finance.')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame((int) $actor->id, (int) $sourceAudit->user_id);
        $this->assertSame('user', $sourceAudit->actor_type);

        $reversalAudit = AuditLog::query()
            ->where('model_type', JournalEntry::class)
            ->where('model_id', $reversal->id)
            ->where('reason', 'Correction approved by finance.')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame((int) $actor->id, (int) $reversalAudit->user_id);

        $lineAudit = AuditLog::query()
            ->where('model_type', JournalEntryLine::class)
            ->where('model_id', $reversal->lines->first()->id)
            ->where('reason', 'Correction approved by finance.')
            ->firstOrFail();
        $this->assertSame((int) $actor->id, (int) $lineAudit->user_id);
    }

    public function test_reversal_rejects_a_closed_reversal_period(): void
    {
        $user = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $svc->post($svc->create([
            'date' => '2026-04-15',
            'description' => 'Period-gated reversal',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $user), $user);

        app(AccountingPeriodService::class)->close(2026, 4, $user);

        $this->expectException(ClosedPeriodException::class);
        $svc->reverse($je, $user, Carbon::parse('2026-04-30'), 'Late correction.');
    }

    public function test_stale_update_and_delete_cannot_mutate_a_posted_entry(): void
    {
        $user = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $svc->create([
            'date' => '2026-04-15',
            'description' => 'Stale draft',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $user);
        $stale = $je->fresh();
        DB::table('journal_entries')->where('id', $je->id)->update([
            'status' => JournalEntryStatus::Posted->value,
        ]);

        try {
            $svc->update($stale, [
                'description' => 'Must not overwrite posted facts',
                'lines' => [
                    ['account_id' => $this->id('1010'), 'debit' => '200.00', 'credit' => '0'],
                    ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '200.00'],
                ],
            ], $user);
            $this->fail('A stale update should be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Only draft entries can be edited.', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only draft entries can be deleted.');
        $svc->delete($stale);
    }

    public function test_archived_draft_can_be_restored_but_active_entry_cannot(): void
    {
        $user = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $svc->create([
            'date' => '2026-04-15',
            'description' => 'Recoverable draft',
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $user);

        $svc->delete($je);
        $restored = $svc->restore($je);
        $this->assertNull($restored->deleted_at);
        $this->assertSame(JournalEntryStatus::Draft, $restored->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Only archived journal entries can be restored.');
        $svc->restore($restored);
    }

    public function test_explicit_null_source_fields_clear_a_system_draft(): void
    {
        $user = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $svc->create([
            'date' => '2026-04-15',
            'description' => 'Nullable provenance',
            'reference_type' => 'asset_depreciation',
            'reference_id' => null,
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $user);

        $updated = $svc->update($je, [
            'reference_type' => null,
            'reference_id' => null,
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $user);

        $this->assertNull($updated->reference_type);
        $this->assertNull($updated->reference_id);
    }

    public function test_manual_endpoint_rejects_user_supplied_source_references(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/journal-entries', [
            'date' => '2026-04-15',
            'description' => 'Forged source reference',
            'reference_type' => 'invoice',
            'reference_id' => 1,
            'lines' => [
                ['account_id' => $this->id('1010'), 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $this->id('3010'), 'debit' => '0', 'credit' => '100.00'],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertSame(0, JournalEntry::query()->where('description', 'Forged source reference')->count());
    }
}
