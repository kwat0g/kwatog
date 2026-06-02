<?php

declare(strict_types=1);

namespace Tests\Feature\Resources;

use App\Modules\Accounting\Models\BillItem;
use App\Modules\Accounting\Resources\BillItemResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guard test — ensures BillItemResource (and by extension all fixed resources)
 * emits a HashID string for 'id', never a raw integer.
 */
class HashIdLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_bill_item_resource_emits_hashid_not_raw_integer(): void
    {
        // Bootstrap FK prerequisites: account → vendor → bill → bill_item

        $accountId = DB::table('accounts')->insertGetId([
            'code'           => 'EXP-9999',
            'name'           => 'Test Expense Account',
            'type'           => 'expense',
            'normal_balance' => 'debit',
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $vendorId = DB::table('vendors')->insertGetId([
            'name'               => 'Test Vendor',
            'payment_terms_days' => 30,
            'is_active'          => true,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);

        $billId = DB::table('bills')->insertGetId([
            'bill_number'  => 'BILL-TEST-001',
            'vendor_id'    => $vendorId,
            'date'         => now()->toDateString(),
            'due_date'     => now()->addDays(30)->toDateString(),
            'subtotal'     => '100.00',
            'vat_amount'   => '12.00',
            'total_amount' => '112.00',
            'amount_paid'  => '0.00',
            'balance'      => '112.00',
            'status'       => 'unpaid',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $item = BillItem::create([
            'bill_id'            => $billId,
            'expense_account_id' => $accountId,
            'description'        => 'Test item',
            'quantity'           => '1.00',
            'unit'               => 'pc',
            'unit_price'         => '100.00',
            'total'              => '100.00',
        ]);

        $arr = (new BillItemResource($item))->toArray(request());

        $this->assertArrayHasKey('id', $arr);
        $this->assertIsString($arr['id']);
        $this->assertFalse(ctype_digit((string) $arr['id']), 'id must be a hashid, not a raw integer');
    }
}
