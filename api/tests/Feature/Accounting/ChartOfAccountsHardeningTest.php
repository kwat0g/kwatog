<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Imports\AccountImporter;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChartOfAccountsHardeningTest extends TestCase
{
    use RefreshDatabase;

    private AccountService $accounts;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->accounts = app(AccountService::class);
        $this->admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
    }

    public function test_update_rejects_self_parent_and_descendant_parent(): void
    {
        $account = Account::query()->where('code', '1010')->firstOrFail();

        try {
            $this->accounts->update($account, ['parent_id' => $account->hash_id]);
            $this->fail('An account must not become its own parent.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('An account cannot be its own parent.', $e->getMessage());
        }

        $child = Account::create([
            'code' => '1099',
            'name' => 'Nested asset',
            'type' => 'asset',
            'normal_balance' => 'debit',
            'parent_id' => $account->id,
        ]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('one of its descendants');
        $this->accounts->update($account->fresh(), ['parent_id' => $child->hash_id]);
    }

    public function test_update_rejects_parent_of_a_different_type(): void
    {
        $account = Account::query()->where('code', '1010')->firstOrFail();
        $liability = Account::query()->where('code', '2000')->firstOrFail();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage("cannot host child of type 'asset'");
        $this->accounts->update($account, ['parent_id' => $liability->hash_id]);
    }

    public function test_tree_fails_safely_when_legacy_data_contains_a_cycle(): void
    {
        $root = Account::query()->where('code', '1000')->firstOrFail();
        $child = Account::query()->where('code', '1010')->firstOrFail();
        DB::table('accounts')->where('id', $root->id)->update(['parent_id' => $child->id]);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('cyclic parent relationship');
        $this->accounts->tree();
    }

    public function test_inactive_account_cannot_receive_a_new_posting(): void
    {
        $cash = Account::query()->where('code', '1010')->firstOrFail();
        $equity = Account::query()->where('code', '3010')->firstOrFail();
        $journals = app(JournalEntryService::class);

        $je = $journals->create([
            'date' => '2026-08-15',
            'description' => 'Inactive account regression',
            'lines' => [
                ['account_id' => $cash->hash_id, 'debit' => '100.00', 'credit' => '0'],
                ['account_id' => $equity->hash_id, 'debit' => '0', 'credit' => '100.00'],
            ],
        ], $this->admin);
        $this->accounts->deactivate($cash);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('inactive and cannot receive new postings');
        $journals->post($je, $this->admin);
    }

    public function test_generic_update_cannot_change_account_status(): void
    {
        $account = Account::query()->where('code', '1010')->firstOrFail();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('dedicated activation endpoint');
        $this->accounts->update($account, ['is_active' => false]);
    }

    public function test_csv_import_uses_the_same_code_and_parent_invariants(): void
    {
        $importer = app(AccountImporter::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('3 to 6 digits');
        $importer->importRow([
            'code' => 'A-1',
            'name' => 'Bad code',
            'type' => 'asset',
            'normal_balance' => 'debit',
        ]);
    }

    public function test_csv_import_rejects_a_parent_of_a_different_type(): void
    {
        $importer = app(AccountImporter::class);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage("cannot host child of type 'expense'");
        $importer->importRow([
            'code' => '6999',
            'name' => 'Invalid child',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'parent_code' => '1000',
        ]);
    }
}
