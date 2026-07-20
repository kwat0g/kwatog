<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FxRate;
use App\Modules\Accounting\Services\CurrencyTranslationService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REC-12 (core) — JPY statement translation (current-rate method).
 *
 * The reconciliation-critical assertion: the translated balance sheet must
 * STILL BALANCE once the CTA plug is inserted, even though assets/liabilities
 * translate at the closing rate and P&L (net income in equity) at the average
 * rate — the whole point of the CTA line.
 */
class CurrencyTranslationTest extends TestCase
{
    use RefreshDatabase;

    private CurrencyTranslationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->svc = app(CurrencyTranslationService::class);
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'T', 'email' => 't_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function acct(string $code): string
    {
        return (string) Account::query()->where('code', $code)->value('id');
    }

    private function seedRates(): void
    {
        // A spread of JPY→PHP rates across the period so the average differs
        // from the closing rate (that difference is what the CTA absorbs).
        FxRate::create(['currency_code' => 'JPY', 'rate_date' => '2026-01-31', 'rate_to_functional' => '0.40000000', 'source' => 'manual']);
        FxRate::create(['currency_code' => 'JPY', 'rate_date' => '2026-02-28', 'rate_to_functional' => '0.38000000', 'source' => 'manual']);
        FxRate::create(['currency_code' => 'JPY', 'rate_date' => '2026-03-31', 'rate_to_functional' => '0.36000000', 'source' => 'manual']);
    }

    public function test_closing_and_average_rate_resolution(): void
    {
        $this->seedRates();

        // Closing = most recent on/before the as-of date.
        $this->assertSame('0.38000000', $this->svc->closingRate('JPY', Carbon::parse('2026-02-28')));
        // A date between rate rows picks the earlier one.
        $this->assertSame('0.40000000', $this->svc->closingRate('JPY', Carbon::parse('2026-02-15')));
        // Average across the quarter = (0.40 + 0.38 + 0.36) / 3 = 0.38.
        $this->assertSame('0.38000000', $this->svc->averageRate('JPY', Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31')));
        // Functional currency is always 1.
        $this->assertSame('1.00000000', $this->svc->closingRate('PHP', Carbon::parse('2026-03-31')));
    }

    public function test_missing_rate_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc->closingRate('JPY', Carbon::parse('2026-03-31')); // no rates seeded
    }

    public function test_translated_balance_sheet_balances_with_cta(): void
    {
        $this->seedRates();
        $by = $this->admin();
        $je = app(JournalEntryService::class);

        // Capital infusion: DR Cash 1,000,000 / CR Capital Stock 1,000,000.
        $e1 = $je->create([
            'date' => '2026-01-15', 'description' => 'Capital',
            'lines' => [
                ['account_id' => $this->acct('1020'), 'debit' => '1000000.00', 'credit' => '0'],
                ['account_id' => $this->acct('3010'), 'debit' => '0', 'credit' => '1000000.00'],
            ],
        ], $by);
        $je->post($e1, $by);

        // A cash sale during the period so there's net income translated at the
        // average rate (while cash sits on the BS at the closing rate).
        $e2 = $je->create([
            'date' => '2026-02-10', 'description' => 'Cash sale',
            'lines' => [
                ['account_id' => $this->acct('1020'), 'debit' => '200000.00', 'credit' => '0'],
                ['account_id' => $this->acct('4010'), 'debit' => '0', 'credit' => '200000.00'],
            ],
        ], $by);
        $je->post($e2, $by);

        $bs = $this->svc->translatedBalanceSheet(Carbon::parse('2026-03-31'), 'JPY');

        $this->assertSame('JPY', $bs['currency']);
        $this->assertSame('0.36000000', $bs['closing_rate']);
        // The whole point: translated BS balances once CTA is inserted.
        $this->assertTrue($bs['balanced'], 'Translated balance sheet must balance with the CTA plug');
        $this->assertSame($bs['total_assets'], $bs['total_liabilities_equity']);

        // CTA line is present in equity.
        $ctaLine = collect($bs['equity']['accounts'])->firstWhere('code', '3900');
        $this->assertNotNull($ctaLine, 'A CTA equity line must be surfaced');

        // Assets translated at closing 0.36: 1,200,000 PHP ÷ 0.36 = 3,333,333.33 JPY.
        $this->assertSame('3333333.33', $bs['total_assets']);
    }

    public function test_translated_income_statement_uses_average_rate(): void
    {
        $this->seedRates();
        $by = $this->admin();
        $je = app(JournalEntryService::class);

        $e = $je->create([
            'date' => '2026-02-10', 'description' => 'Cash sale',
            'lines' => [
                ['account_id' => $this->acct('1020'), 'debit' => '380000.00', 'credit' => '0'],
                ['account_id' => $this->acct('4010'), 'debit' => '0', 'credit' => '380000.00'],
            ],
        ], $by);
        $je->post($e, $by);

        $is = $this->svc->translatedIncomeStatement(Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'), 'JPY');

        $this->assertSame('0.38000000', $is['average_rate']);
        // Revenue 380,000 PHP ÷ 0.38 average = 1,000,000 JPY.
        $this->assertSame('1000000.00', $is['statement']['revenue']['total']);
        $this->assertSame('1000000.00', $is['statement']['net_income']);
    }

    public function test_translation_routes_are_permission_gated(): void
    {
        $this->getJson('/api/v1/accounting/currency/fx-rates')->assertStatus(401);
    }

    /**
     * Exercise the ACTUAL authenticated GET/POST fx-rate paths (not just the
     * 401 gate). This is the coverage that catches route→controller method
     * name mismatches — the class of bug that would 500 every real request
     * while a 401-only test stays green.
     */
    public function test_fx_rate_store_and_list_round_trip(): void
    {
        $admin = $this->admin();

        // POST a rate, then GET it back — both must hit real controller methods.
        $this->actingAs($admin)
            ->postJson('/api/v1/accounting/currency/fx-rates', [
                'currency_code'      => 'JPY',
                'rate_date'          => '2026-03-31',
                'rate_to_functional' => '0.36000000',
            ])
            ->assertStatus(201) // Resource returns 201 on a fresh insert (wasRecentlyCreated)
            ->assertJsonPath('data.currency_code', 'JPY');

        $this->actingAs($admin)
            ->getJson('/api/v1/accounting/currency/fx-rates?currency_code=JPY')
            ->assertStatus(200)
            ->assertJsonPath('data.0.currency_code', 'JPY');
    }

    /** The translated balance-sheet endpoint returns a balanced pack over HTTP. */
    public function test_translated_balance_sheet_endpoint_returns_balanced_pack(): void
    {
        $this->seedRates();
        $by = $this->admin();
        $svc = app(JournalEntryService::class);

        // Capital infusion so there is something on the balance sheet.
        $je = $svc->create([
            'date' => '2026-02-15', 'description' => 'Capital',
            'lines' => [
                ['account_id' => $this->acct('1020'), 'debit' => '1000000.00', 'credit' => '0'],
                ['account_id' => $this->acct('3010'), 'debit' => '0', 'credit' => '1000000.00'],
            ],
        ], $by);
        $svc->post($je, $by);

        $this->actingAs($by)
            ->getJson('/api/v1/accounting/currency/balance-sheet?as_of=2026-03-31&currency=JPY')
            ->assertStatus(200)
            ->assertJsonPath('data.currency', 'JPY')
            ->assertJsonPath('data.balanced', true);
    }
}
