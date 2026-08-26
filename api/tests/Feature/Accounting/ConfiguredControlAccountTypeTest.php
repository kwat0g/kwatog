<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\AccountingAccountPolicyService;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M025 / F-001 — a configured control account must be of the right TYPE, not
 * merely active.
 *
 * A configured code is deployment data, so it can be pointed anywhere. The
 * canonical journal boundary rejects missing and inactive accounts, but it
 * deliberately does not infer semantic types from an arbitrary line — so
 * without these checks an active account of the wrong class can receive a
 * specifically-AP or specifically-VAT posting and produce a balanced journal
 * with incorrect financial classification.
 */
class ConfiguredControlAccountTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    public function test_each_control_account_role_declares_its_required_type(): void
    {
        $policy = app(AccountingAccountPolicyService::class);

        $this->assertSame(AccountType::Asset, $policy->typeFor($policy->ar()));
        $this->assertSame(AccountType::Liability, $policy->typeFor($policy->ap()));
        $this->assertSame(AccountType::Liability, $policy->typeFor($policy->vatOutput()));
        $this->assertSame(AccountType::Asset, $policy->typeFor($policy->vatInput()));
        $this->assertSame(AccountType::Revenue, $policy->typeFor($policy->discount()));
    }

    public function test_a_code_this_policy_does_not_own_has_no_required_type(): void
    {
        $policy = app(AccountingAccountPolicyService::class);

        // 5010 is an expense account an operator may legitimately pick for a
        // bill line. It is not a control account, so forcing it through a
        // control-account rule would be wrong.
        $this->assertNull($policy->typeFor('5010'));
        $this->assertSame(
            Account::query()->where('code', '5010')->value('id'),
            $policy->controlAccountId('5010'),
        );
    }

    public function test_correctly_typed_control_accounts_resolve(): void
    {
        $policy = app(AccountingAccountPolicyService::class);

        $this->assertSame(
            Account::query()->where('code', $policy->ap())->value('id'),
            $policy->controlAccountId($policy->ap()),
        );
        $this->assertSame(
            Account::query()->where('code', $policy->vatInput())->value('id'),
            $policy->controlAccountId($policy->vatInput()),
        );
    }

    public function test_an_active_but_wrongly_typed_ap_code_is_refused(): void
    {
        // 1010 Cash on Hand is active, and an asset. AP must be a liability.
        app(SettingsService::class)->set('accounting.accounts.ap_code', '1010', 'accounting');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Account 1010 must be of type liability.');

        app(AccountingAccountPolicyService::class)->controlAccountId('1010');
    }

    public function test_an_active_but_wrongly_typed_vat_input_code_is_refused(): void
    {
        // 3010 Capital Stock is active, and equity. VAT Input is an asset.
        app(SettingsService::class)->set('accounting.accounts.vat_input_code', '3010', 'accounting');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Account 3010 must be of type asset.');

        app(AccountingAccountPolicyService::class)->controlAccountId('3010');
    }

    /**
     * The map is keyed by code, so pointing two roles at one account collapses
     * them onto the first matching rule rather than failing. Pinned because it
     * is the one way a "wrong type" check can silently pass: AP and VAT Input
     * disagree about the required type, and here AP is checked first.
     */
    public function test_two_roles_sharing_one_code_resolve_against_the_first_rule(): void
    {
        $policy = app(AccountingAccountPolicyService::class);
        $ap = $policy->ap();
        app(SettingsService::class)->set('accounting.accounts.vat_input_code', $ap, 'accounting');

        $this->assertSame(AccountType::Liability, app(AccountingAccountPolicyService::class)->typeFor($ap));
        $this->assertSame(
            Account::query()->where('code', $ap)->value('id'),
            app(AccountingAccountPolicyService::class)->controlAccountId($ap),
        );
    }

    public function test_an_inactive_control_account_is_still_refused(): void
    {
        $policy = app(AccountingAccountPolicyService::class);
        Account::query()->where('code', $policy->ap())->update(['is_active' => false]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('is inactive and cannot receive new postings');

        $policy->controlAccountId($policy->ap());
    }

    /**
     * The gate that matters: the refusal has to land before anything is
     * committed, not after a journal exists.
     */
    public function test_a_wrongly_typed_ap_code_commits_no_bill_and_no_journal(): void
    {
        $user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
            'is_active' => true,
        ]);
        $vendor = Vendor::create(['name' => 'Control Account Vendor']);
        $expense = Account::query()->where('code', '5010')->firstOrFail();

        // Point AP at an active asset account.
        app(SettingsService::class)->set('accounting.accounts.ap_code', '1010', 'accounting');

        $billsBefore = Bill::query()->count();
        $journalsBefore = JournalEntry::query()->count();

        try {
            app(BillService::class)->create([
                'bill_number' => 'CA-T-'.substr(uniqid(), -5),
                'vendor_id' => $vendor->hash_id,
                'provenance_type' => 'service',
                'exception_evidence' => 'Approved service completion evidence.',
                'exception_approved' => true,
                'date' => now()->toDateString(),
                'is_vatable' => false,
                'items' => [[
                    'expense_account_id' => $expense->hash_id,
                    'description' => 'Control account type fixture',
                    'quantity' => '1',
                    'unit_price' => '100.00',
                ]],
            ], $user);
            $this->fail('A bill posted against a wrongly-typed AP account should not be accepted.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('must be of type liability', $e->getMessage());
        }

        $this->assertSame($billsBefore, Bill::query()->count(), 'The bill must not survive the failed post.');
        $this->assertSame($journalsBefore, JournalEntry::query()->count(), 'No journal may be committed.');
    }
}
