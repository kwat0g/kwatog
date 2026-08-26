<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
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

        // Decode instead of string-matching the raw body: fputcsv() encloses any
        // field containing a space, so the header really is
        // `"Row Type",Currency,Code,Name,Type,"Debit Total",...` and a hardcoded
        // unquoted string can never match it. The decoded columns are the
        // contract worth pinning.
        $rows = $this->parseCsv($response->streamedContent());

        self::assertSame(
            ['Row Type', 'Currency', 'Code', 'Name', 'Type', 'Debit Total', 'Credit Total', 'Balance', 'Side'],
            $rows[0],
        );
        // A dropped column would shift debit into credit, so pin every row width.
        foreach ($rows as $i => $row) {
            self::assertCount(count($rows[0]), $row, "CSV row {$i} must have the same width as the header");
        }

        $byRowType = [];
        foreach (array_slice($rows, 1) as $row) {
            $byRowType[$row[0]] = $row;
        }

        // Currency metadata rides every row, not only the header.
        self::assertSame(['Total', 'PHP', '', '', '', '0.00', '0.00', '', ''], $byRowType['Total'] ?? null);
        // Reconciliation metadata: the debit/credit totals above plus an explicit flag.
        self::assertSame(['Status', 'PHP', '', 'Reconciled', '', '', '', 'true', ''], $byRowType['Status'] ?? null);
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

    /**
     * The authorized PDF path had never been executed — only its 403 and 422
     * arms were covered — and that is exactly how a call to a nonexistent
     * request accessor survived in PdfController::balanceSheet(). Render all
     * three statements against a real posted ledger so the blade templates and
     * the injected StatementMoneyFormatter actually run.
     */
    public function test_export_capable_user_can_render_every_statement_pdf(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->postBalancedLedger();

        $user = $this->userWithPermissions([
            'accounting.statements.view',
            'accounting.statements.export',
        ]);

        $urls = [
            '/api/v1/accounting/statements/trial-balance/pdf?from=2026-04-01&to=2026-04-30',
            '/api/v1/accounting/statements/income-statement/pdf?from=2026-04-01&to=2026-04-30',
            '/api/v1/accounting/statements/balance-sheet/pdf?as_of=2026-04-30',
        ];

        foreach ($urls as $url) {
            $response = $this->actingAs($user)->get($url)->assertOk();

            self::assertSame('application/pdf', $response->headers->get('Content-Type'), $url);
            self::assertStringStartsWith('%PDF', $response->getContent(), $url);
        }
    }

    /**
     * DR Cash 12,345,678.90 / CR Capital Stock, plus a cash sale so the income
     * statement and the balance sheet's synthetic net-income line both have
     * rows to format.
     */
    private function postBalancedLedger(): void
    {
        $maker = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        $journalEntries = app(JournalEntryService::class);

        $code = static fn (string $code): string => Account::query()->where('code', $code)->firstOrFail()->hash_id;

        $capital = $journalEntries->create([
            'date' => '2026-04-01',
            'description' => 'Capital infusion',
            'lines' => [
                ['account_id' => $code('1020'), 'debit' => '12345678.90', 'credit' => '0'],
                ['account_id' => $code('3010'), 'debit' => '0', 'credit' => '12345678.90'],
            ],
        ], $maker);
        $journalEntries->post($capital, $maker);

        $sale = $journalEntries->create([
            'date' => '2026-04-10',
            'description' => 'Cash sale',
            'lines' => [
                ['account_id' => $code('1020'), 'debit' => '5000.00', 'credit' => '0'],
                ['account_id' => $code('4010'), 'debit' => '0', 'credit' => '5000.00'],
            ],
        ], $maker);
        $journalEntries->post($sale, $maker);
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsv(string $body): array
    {
        $lines = array_values(array_filter(
            preg_split('/\R/', trim($body)) ?: [],
            static fn (string $line): bool => $line !== '',
        ));

        return array_map(static fn (string $line): array => str_getcsv($line), $lines);
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
