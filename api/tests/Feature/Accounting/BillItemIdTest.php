<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BillService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H-7 — bill_items.item_id is persisted from request payload.
 */
class BillItemIdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function newUser(): User
    {
        $roleId = Role::query()->where('slug', 'system_admin')->value('id');
        return User::create([
            'name' => 'Finance', 'email' => 'fin_'.uniqid().'@x.test', 'password' => bcrypt('Password1!'),
            'role_id' => $roleId,
        ]);
    }

    public function test_bill_create_persists_item_id_from_request(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create(['name' => 'Acme', 'payment_terms_days' => 30]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;
        $item = Item::factory()->create();

        $svc = app(BillService::class);
        $bill = $svc->create([
            'bill_number' => 'INV-FK-001',
            'vendor_id'   => $vendor->hash_id,
            'date'        => '2026-04-10',
            'is_vatable'  => false,
            'items'       => [
                [
                    'expense_account_id' => $expenseId,
                    'item_id'            => $item->hash_id,
                    'description'        => 'Resin Type A',
                    'quantity'           => '5',
                    'unit_price'         => '100.00',
                ],
            ],
        ], $user);

        $row = BillItem::query()->where('bill_id', $bill->id)->firstOrFail();
        $this->assertSame($item->id, $row->item_id,
            'BillItem.item_id must be persisted from request payload (decoded HashID).');
        $this->assertNotNull($row->item, 'item() relation must hydrate.');
    }

    public function test_bill_create_accepts_null_item_id(): void
    {
        $user = $this->newUser();
        $vendor = Vendor::create(['name' => 'Misc', 'payment_terms_days' => 30]);
        $expenseId = Account::query()->where('code', '5010')->firstOrFail()->hash_id;

        $svc = app(BillService::class);
        $bill = $svc->create([
            'bill_number' => 'INV-FK-002',
            'vendor_id'   => $vendor->hash_id,
            'date'        => '2026-04-10',
            'is_vatable'  => false,
            'items'       => [
                [
                    'expense_account_id' => $expenseId,
                    // no item_id — miscellaneous expense not tied to inventory
                    'description'        => 'Misc service',
                    'quantity'           => '1',
                    'unit_price'         => '50.00',
                ],
            ],
        ], $user);

        $row = BillItem::query()->where('bill_id', $bill->id)->firstOrFail();
        $this->assertNull($row->item_id,
            'BillItem.item_id must remain NULL when not provided (e.g. miscellaneous expense lines).');
    }
}
