<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Enums\AccountingPeriodStatus;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * M026 — the ledger invariants, executed rather than reasoned about.
 *
 * Every assertion here is a money assertion, so each one is measured against
 * real PostgreSQL rows: header totals are compared to `SUM(lines)` in SQL, and
 * the immutability guards are probed through Eloquent *and* through raw queries,
 * because the database triggers exist precisely for the writer that bypasses the
 * service.
 *
 * A PostgreSQL trigger `RAISE` aborts the enclosing transaction (25P02), so each
 * refusal probe runs inside its own savepoint via {@see refuses()}. Without that
 * the first refused statement poisons every later assertion in the same test.
 */
class JournalLedgerInvariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function user(string $slug = 'system_admin'): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'is_active' => true,
        ]);
    }

    private function acct(string $code): string
    {
        return Account::query()->where('code', $code)->firstOrFail()->hash_id;
    }

    private function draft(User $u, string $date, string $amt = '100.00'): JournalEntry
    {
        return app(JournalEntryService::class)->create([
            'date' => $date,
            'description' => 'Ledger invariant fixture',
            'lines' => [
                ['account_id' => $this->acct('1010'), 'debit' => $amt, 'credit' => '0'],
                ['account_id' => $this->acct('3010'), 'debit' => '0', 'credit' => $amt],
            ],
        ], $u);
    }

    /**
     * Assert a write is refused, isolating the attempt in a savepoint so a
     * PostgreSQL trigger abort does not invalidate the rest of the test.
     */
    private function refuses(string $what, callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail("Expected {$what} to be refused, but it succeeded.");
        } catch (Throwable $e) {
            if ($e instanceof AssertionFailedError) {
                throw $e;
            }
            $this->assertTrue(true, $what.' refused: '.$e->getMessage());
        }
    }

    /** Header totals must equal the sum of the persisted lines, for every row. */
    private function assertHeadersEqualLines(): void
    {
        $mismatch = DB::select(<<<'SQL'
SELECT je.id, je.entry_number, je.status,
       je.total_debit, je.total_credit,
       COALESCE(SUM(l.debit), 0)  AS line_debit,
       COALESCE(SUM(l.credit), 0) AS line_credit,
       count(l.id) AS n_lines
FROM journal_entries je
LEFT JOIN journal_entry_lines l ON l.journal_entry_id = je.id
GROUP BY je.id, je.entry_number, je.status, je.total_debit, je.total_credit
HAVING je.total_debit  <> COALESCE(SUM(l.debit), 0)
    OR je.total_credit <> COALESCE(SUM(l.credit), 0)
ORDER BY je.id
SQL);

        $this->assertSame([], $mismatch, 'A journal header disagrees with the sum of its own lines: '
            .json_encode($mismatch));
    }

    // ─────────────────────────────────────────────────────────────────
    // Invariant 1 — an entry balances, including via per-line rounding
    // ─────────────────────────────────────────────────────────────────

    public function test_lines_that_each_round_correctly_but_total_differently_are_refused(): void
    {
        $u = $this->user();
        $svc = app(JournalEntryService::class);

        // 1.005 + 1.005 each round half-up to 1.01, so the debit side totals
        // 2.02 against a 2.01 credit. Comparing raw input would have balanced.
        try {
            $svc->create([
                'date' => '2029-04-15', 'description' => 'rounding drift',
                'lines' => [
                    ['account_id' => $this->acct('1010'), 'debit' => '1.005', 'credit' => '0'],
                    ['account_id' => $this->acct('1020'), 'debit' => '1.005', 'credit' => '0'],
                    ['account_id' => $this->acct('3010'), 'debit' => '0', 'credit' => '2.01'],
                ],
            ], $u);
            $this->fail('A one-centavo rounding imbalance was accepted.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('not balanced', $e->getMessage());
            $this->assertStringContainsString('2.02', $e->getMessage());
            $this->assertStringContainsString('2.01', $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::query()->count(), 'A refused create must leave no row.');
    }

    public function test_an_over_precision_amount_is_rejected_not_silently_rounded(): void
    {
        $admin = $this->user();

        $r = $this->actingAs($admin)->postJson('/api/v1/journal-entries', [
            'date' => '2029-04-15', 'description' => 'over precision',
            'lines' => [
                ['account_id' => $this->acct('1010'), 'debit' => '1.999', 'credit' => '0'],
                ['account_id' => $this->acct('3010'), 'debit' => '0', 'credit' => '2.00'],
            ],
        ]);

        $r->assertStatus(422);
        $this->assertArrayHasKey('lines.0.debit', $r->json('errors') ?? [],
            'The centavo contract must be refused at the field that broke it.');
        $this->assertSame(0, JournalEntry::query()->count(),
            'An entry the operator did not ask for must not be persisted.');
    }

    /** @return array<string, array{0:string}> */
    public static function unrepresentableAmountProvider(): array
    {
        return [
            // is_numeric('1e3') is true, so `numeric` alone let this reach
            // BCMath, which threw ValueError -> HTTP 500.
            'scientific notation' => ['1e3'],
            // decimal(15,2) tops out below 10^13; `numeric|min:0` had no upper
            // bound, so this reached PostgreSQL as a 22003 overflow -> HTTP 500.
            'beyond decimal(15,2)' => ['99999999999999999.00'],
        ];
    }

    #[DataProvider('unrepresentableAmountProvider')]
    public function test_an_unrepresentable_amount_is_a_422_not_a_500(string $amount): void
    {
        $admin = $this->user();

        $r = $this->actingAs($admin)->postJson('/api/v1/journal-entries', [
            'date' => '2029-04-15', 'description' => 'unrepresentable',
            'lines' => [
                ['account_id' => $this->acct('1010'), 'debit' => $amount, 'credit' => '0'],
                ['account_id' => $this->acct('3010'), 'debit' => '0', 'credit' => $amount],
            ],
        ]);

        $this->assertSame(422, $r->status(),
            "debit={$amount} must be a validation failure, not a server fault. Body: ".$r->getContent());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    // ─────────────────────────────────────────────────────────────────
    // Invariant 2 — a posted entry is immutable, across every verb
    // ─────────────────────────────────────────────────────────────────

    public function test_a_posted_entry_is_immutable_through_every_write_verb(): void
    {
        $maker = $this->user();
        $checker = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $svc->post($this->draft($maker, '2029-04-15'), $checker);
        $id = $je->id;
        $otherAccount = (int) Account::query()->where('code', '1020')->value('id');

        // 1. update a business column
        $this->refuses('an Eloquent update of a posted description',
            fn () => JournalEntry::findOrFail($id)->update(['description' => 'tampered']));
        $this->refuses('a raw update of a posted total',
            fn () => DB::table('journal_entries')->where('id', $id)->update(['total_debit' => '1.00']));

        // 2. change the date after posting — this is what re-dates a figure
        //    into another accounting period
        $this->refuses('re-dating a posted entry',
            fn () => JournalEntry::findOrFail($id)->update(['date' => '2029-05-01']));
        $this->refuses('a raw re-date of a posted entry',
            fn () => DB::table('journal_entries')->where('id', $id)->update(['date' => '2029-05-01']));

        // 3. soft-delete (hide) it
        $this->refuses('archiving a posted entry',
            fn () => JournalEntry::findOrFail($id)->delete());
        $this->refuses('a raw deleted_at on a posted entry',
            fn () => DB::table('journal_entries')->where('id', $id)->update(['deleted_at' => now()]));

        // 4. force-delete it
        $this->refuses('force-deleting a posted entry',
            fn () => JournalEntry::findOrFail($id)->forceDelete());
        $this->refuses('a raw DELETE of a posted entry',
            fn () => DB::table('journal_entries')->where('id', $id)->delete());

        // 5. add / remove / mutate a line
        $this->refuses('adding a line to a posted entry', fn () => JournalEntryLine::create([
            'journal_entry_id' => $id, 'account_id' => $otherAccount,
            'line_no' => 9, 'debit' => '5.00', 'credit' => '0',
        ]));
        $this->refuses('a raw line insert on a posted entry',
            fn () => DB::table('journal_entry_lines')->insert([
                'journal_entry_id' => $id, 'account_id' => $otherAccount,
                'line_no' => 8, 'debit' => '5.00', 'credit' => '0',
            ]));
        $this->refuses('a raw line delete on a posted entry',
            fn () => DB::table('journal_entry_lines')->where('journal_entry_id', $id)->delete());
        $this->refuses('a raw line amount update on a posted entry',
            fn () => DB::table('journal_entry_lines')->where('journal_entry_id', $id)
                ->update(['debit' => '1.00']));

        // and an out-of-band status flip in either direction
        $this->refuses('a raw posted -> draft status flip',
            fn () => DB::table('journal_entries')->where('id', $id)->update(['status' => 'draft']));
        $this->refuses('a raw posted -> reversed flip with no linked reversal',
            fn () => DB::table('journal_entries')->where('id', $id)->update(['status' => 'reversed']));

        $fresh = JournalEntry::withTrashed()->findOrFail($id);
        $this->assertSame(JournalEntryStatus::Posted, $fresh->status);
        $this->assertSame('Ledger invariant fixture', $fresh->description);
        $this->assertSame('2029-04-15', $fresh->date->toDateString());
        $this->assertSame('100.00', (string) $fresh->total_debit);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame(2, JournalEntryLine::query()->where('journal_entry_id', $id)->count());
        $this->assertHeadersEqualLines();
    }

    public function test_the_service_refuses_to_edit_or_archive_a_posted_entry(): void
    {
        $maker = $this->user();
        $checker = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $svc->post($this->draft($maker, '2029-04-15'), $checker);

        $this->refuses('JournalEntryService::update on a posted entry', fn () => $svc->update(
            JournalEntry::findOrFail($je->id),
            ['description' => 'x', 'lines' => [
                ['account_id' => $this->acct('1010'), 'debit' => '1.00', 'credit' => '0'],
                ['account_id' => $this->acct('3010'), 'debit' => '0', 'credit' => '1.00'],
            ]],
            $checker,
        ));
        $this->refuses('JournalEntryService::delete on a posted entry',
            fn () => $svc->delete(JournalEntry::findOrFail($je->id)));
    }

    /**
     * The trigger's draft branch validated the status transition but not
     * `deleted_at`, so a raw writer could archive a draft (lines intact) and
     * then promote it. The result was a POSTED row that Eloquent's soft-delete
     * scope hides from the journal list while every raw statement aggregate
     * still counts its lines.
     */
    public function test_an_archived_draft_cannot_be_promoted_to_posted(): void
    {
        $u = $this->user();
        $je = $this->draft($u, '2029-04-15', '888.00');

        DB::table('journal_entries')->where('id', $je->id)->update(['deleted_at' => now()]);
        $this->assertSame(2, DB::table('journal_entry_lines')
            ->where('journal_entry_id', $je->id)->count(), 'fixture: lines must still be present');

        $this->refuses('promoting an archived draft to posted',
            fn () => DB::table('journal_entries')->where('id', $je->id)->update(['status' => 'posted']));

        $this->assertSame('draft', DB::table('journal_entries')->where('id', $je->id)->value('status'));

        // No posted row may be invisible to Eloquent while visible to raw SQL.
        $hidden = DB::table('journal_entries')
            ->where('status', 'posted')->whereNotNull('deleted_at')->count();
        $this->assertSame(0, $hidden,
            'A soft-deleted POSTED entry is hidden from the journal list but counted by every '
            .'statement aggregate that joins journal_entry_lines without a deleted_at filter.');
    }

    // ─────────────────────────────────────────────────────────────────
    // Invariant 3 — archiving a draft is reversible
    // ─────────────────────────────────────────────────────────────────

    /**
     * `delete()` used to hard-delete the lines (JournalEntryLine has no
     * SoftDeletes trait), while `restore()` only restores the header. Restore
     * therefore returned a header still claiming its total with zero lines
     * behind it: un-postable, and advertised at full value in the journal list.
     */
    public function test_archiving_and_restoring_a_draft_preserves_its_line_aggregate(): void
    {
        $u = $this->user();
        $svc = app(JournalEntryService::class);
        $je = $this->draft($u, '2029-04-15', '100.00');
        $id = $je->id;

        $before = JournalEntryLine::query()->where('journal_entry_id', $id)
            ->orderBy('line_no')->get(['account_id', 'line_no', 'debit', 'credit'])->toArray();
        $this->assertCount(2, $before);

        $svc->delete($je);
        $this->assertNotNull(JournalEntry::withTrashed()->findOrFail($id)->deleted_at);
        $this->assertNull(JournalEntry::query()->find($id), 'an archived entry must leave the default scope');

        $restored = $svc->restore(JournalEntry::withTrashed()->findOrFail($id));

        $this->assertNull($restored->deleted_at);
        $this->assertSame(JournalEntryStatus::Draft, $restored->status);
        $after = JournalEntryLine::query()->where('journal_entry_id', $id)
            ->orderBy('line_no')->get(['account_id', 'line_no', 'debit', 'credit'])->toArray();
        $this->assertSame($before, $after, 'restore must return the entry the operator archived, lines and all');
        $this->assertHeadersEqualLines();

        // And the restored draft must be postable again.
        $posted = $svc->post(JournalEntry::findOrFail($id), $this->user());
        $this->assertSame(JournalEntryStatus::Posted, $posted->status);
        $this->assertSame('100.00', (string) $posted->total_debit);
        $this->assertHeadersEqualLines();
    }

    // ─────────────────────────────────────────────────────────────────
    // Invariant 4 — a closed period refuses every write path
    // ─────────────────────────────────────────────────────────────────

    public function test_every_write_path_refuses_a_closed_period(): void
    {
        $maker = $this->user();
        $checker = $this->user();
        $svc = app(JournalEntryService::class);

        $forPost = $this->draft($maker, '2029-06-15');
        $forSystemPost = $this->draft($maker, '2029-06-16');
        $forUpdate = $this->draft($maker, '2029-06-17');
        $posted = $svc->post($this->draft($maker, '2029-06-18'), $checker);

        AccountingPeriod::create(['year' => 2029, 'month' => 6, 'status' => AccountingPeriodStatus::Closed]);
        AccountingPeriod::create(['year' => 2029, 'month' => 7, 'status' => AccountingPeriodStatus::Closed]);

        $this->refuses('create into a closed period', fn () => $this->draft($maker, '2029-06-20'));
        $this->refuses('update into a closed period', fn () => $svc->update($forUpdate, [
            'date' => '2029-06-21',
            'lines' => [
                ['account_id' => $this->acct('1010'), 'debit' => '5.00', 'credit' => '0'],
                ['account_id' => $this->acct('3010'), 'debit' => '0', 'credit' => '5.00'],
            ],
        ], $maker));
        $this->refuses('post into a closed period',
            fn () => $svc->post(JournalEntry::findOrFail($forPost->id), $checker));
        $this->refuses('postSystem into a closed period',
            fn () => $svc->postSystem(JournalEntry::findOrFail($forSystemPost->id), null));
        $this->refuses('reverse into a closed period', fn () => $svc->reverse(
            JournalEntry::findOrFail($posted->id), $checker, Carbon::parse('2029-07-05'), 'closed target'));
    }

    public function test_a_closed_period_reaches_the_client_as_an_actionable_422(): void
    {
        $admin = $this->user();
        $svc = app(JournalEntryService::class);
        $posted = $svc->post($this->draft($admin, '2029-08-10'), $this->user());
        AccountingPeriod::create(['year' => 2029, 'month' => 9, 'status' => AccountingPeriodStatus::Closed]);

        // reverse() is the path JournalEntryController does NOT name in its
        // catch, so this proves the exception's own render arm answers 422.
        $r = $this->actingAs($admin)->postJson(
            '/api/v1/journal-entries/'.$posted->hash_id.'/reverse',
            ['reverse_date' => '2029-09-10', 'reason' => 'closed target'],
        );

        $r->assertStatus(422);
        $this->assertStringContainsString('2029-09 is closed', (string) $r->json('message'));
        $this->assertStringContainsString('Reopen the period', (string) $r->json('message'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Invariant 5 — maker-checker
    // ─────────────────────────────────────────────────────────────────

    public function test_a_maker_cannot_post_their_own_entry_but_an_override_holder_can(): void
    {
        $svc = app(JournalEntryService::class);
        $fin = $this->user('finance_officer');
        $fin2 = $this->user('finance_officer');
        $admin = $this->user('system_admin');

        // The seeder withholds the override from finance_officer on purpose.
        $this->assertFalse($fin->hasPermission('accounting.journal.self_post_override'));
        $this->assertSame(['system_admin'], DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->where('permissions.slug', 'accounting.journal.self_post_override')
            ->pluck('roles.slug')->all());

        $ownDraft = $this->draft($fin, '2029-09-01');
        try {
            $svc->post(JournalEntry::findOrFail($ownDraft->id), $fin);
            $this->fail('A maker posted their own journal entry.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('segregation of duties', $e->getMessage());
        }
        $this->assertSame(JournalEntryStatus::Draft, JournalEntry::findOrFail($ownDraft->id)->status);

        // A different checker may post it.
        $this->assertSame(JournalEntryStatus::Posted,
            $svc->post(JournalEntry::findOrFail($ownDraft->id), $fin2)->status);

        // The override holder may self-post.
        $this->assertSame(JournalEntryStatus::Posted,
            $svc->post($this->draft($admin, '2029-09-03'), $admin)->status);
    }

    // ─────────────────────────────────────────────────────────────────
    // Invariant 6 — reversal linkage
    // ─────────────────────────────────────────────────────────────────

    public function test_a_reversal_is_balanced_linked_both_ways_and_cannot_repeat(): void
    {
        $maker = $this->user();
        $checker = $this->user();
        $svc = app(JournalEntryService::class);
        $src = $svc->post($this->draft($maker, '2029-10-15', '250.00'), $checker);

        $rev = $svc->reverse(JournalEntry::findOrFail($src->id), $checker, null, 'operator reason');
        $srcFresh = JournalEntry::findOrFail($src->id);

        // linked both ways
        $this->assertSame((int) $rev->id, (int) $srcFresh->reversed_by_entry_id);
        $this->assertSame('journal_entry_reversal', $rev->reference_type);
        $this->assertSame((int) $src->id, (int) $rev->reference_id);

        // balanced, and a true mirror of the source
        $this->assertSame(JournalEntryStatus::Reversed, $srcFresh->status);
        $this->assertSame(JournalEntryStatus::Posted, $rev->status);
        $this->assertSame('250.00', (string) $rev->total_debit);
        $this->assertSame('250.00', (string) $rev->total_credit);
        $this->assertSame('operator reason', $rev->reversal_reason);
        $this->assertHeadersEqualLines();

        $srcLines = JournalEntryLine::query()->where('journal_entry_id', $src->id)
            ->orderBy('line_no')->get();
        $revLines = JournalEntryLine::query()->where('journal_entry_id', $rev->id)
            ->orderBy('line_no')->get();
        $this->assertCount($srcLines->count(), $revLines);
        foreach ($srcLines as $i => $line) {
            $this->assertSame((string) $line->debit, (string) $revLines[$i]->credit);
            $this->assertSame((string) $line->credit, (string) $revLines[$i]->debit);
            $this->assertSame((int) $line->account_id, (int) $revLines[$i]->account_id);
        }

        // and it cannot be reversed twice
        $this->refuses('reversing an already-reversed entry',
            fn () => $svc->reverse(JournalEntry::findOrFail($src->id), $checker, null, 'again'));
        $this->assertSame(1, JournalEntry::query()
            ->where('reference_type', 'journal_entry_reversal')
            ->where('reference_id', $src->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────
    // Invariant 7 — no raw database id in any payload
    // ─────────────────────────────────────────────────────────────────

    public function test_no_journal_payload_carries_a_raw_database_id(): void
    {
        $admin = $this->user();
        $svc = app(JournalEntryService::class);
        $src = $svc->post($this->draft($admin, '2029-11-15'), $admin);
        $rev = $svc->reverse(JournalEntry::findOrFail($src->id), $admin, null, 'why');

        foreach ([
            'detail' => '/api/v1/journal-entries/'.$rev->hash_id,
            'list' => '/api/v1/journal-entries?per_page=100',
        ] as $what => $url) {
            $r = $this->actingAs($admin)->getJson($url);
            $r->assertOk();
            $payload = $r->json();
            $rows = $what === 'list' ? ($payload['data'] ?? []) : [$payload['data']];

            foreach ($rows as $row) {
                $label = $row['reference_label'] ?? null;
                if ($label === null) {
                    continue;
                }
                // `reference_id` is hashed one key above this label. Printing the
                // integer here handed a client both halves of that mapping.
                $this->assertDoesNotMatchRegularExpression(
                    '/#\s*\d+/', $label,
                    "reference_label in the {$what} payload embeds a raw database id: {$label}",
                );
                $this->assertStringNotContainsString('App\\', $label,
                    "reference_label in the {$what} payload leaks an implementation class name: {$label}");
            }
        }
    }

    public function test_an_unknown_journal_entry_is_a_bare_404_with_no_id_oracle(): void
    {
        $admin = $this->user();

        foreach (['9999999', 'zzzznotahash', '1'] as $candidate) {
            $r = $this->actingAs($admin)->getJson('/api/v1/journal-entries/'.$candidate);
            $this->assertSame(404, $r->status(), "GET /journal-entries/{$candidate}");
            // json_encode escapes '/', so match on the decoded body, never on
            // assertStringNotContainsString against the raw content.
            $this->assertArrayNotHasKey('id', (array) $r->json());
            $this->assertArrayNotHasKey('reason', (array) $r->json());
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Invariant 8 — the aggregate equals the lines, always
    // ─────────────────────────────────────────────────────────────────

    public function test_every_header_total_equals_the_sum_of_its_lines_across_the_lifecycle(): void
    {
        $maker = $this->user();
        $checker = $this->user();
        $svc = app(JournalEntryService::class);

        // create -> edit (line count changes) -> post -> reverse, plus an
        // archive/restore round trip, then reconcile every row in SQL.
        $edited = $this->draft($maker, '2029-12-02', '10.00');
        $svc->update($edited, ['lines' => [
            ['account_id' => $this->acct('1010'), 'debit' => '4.00', 'credit' => '0'],
            ['account_id' => $this->acct('1020'), 'debit' => '6.00', 'credit' => '0'],
            ['account_id' => $this->acct('3010'), 'debit' => '0', 'credit' => '10.00'],
        ]], $maker);
        $this->assertHeadersEqualLines();

        $posted = $svc->post(JournalEntry::findOrFail($edited->id), $checker);
        $this->assertSame('10.00', (string) $posted->total_debit);
        $this->assertHeadersEqualLines();

        $svc->reverse(JournalEntry::findOrFail($posted->id), $checker, null, 'reconcile');
        $this->assertHeadersEqualLines();

        $archived = $this->draft($maker, '2029-12-03', '33.33');
        $svc->delete($archived);
        $svc->restore(JournalEntry::withTrashed()->findOrFail($archived->id));
        $this->assertHeadersEqualLines();

        // Soft-deleted entries must be excluded from, or included in, every
        // aggregate consistently — never one of each.
        $this->assertSame(0, DB::table('journal_entries')
            ->where('status', 'posted')->whereNotNull('deleted_at')->count());
    }
}
