<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Resources\PurchaseRequestItemResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guard test — ensures resources that were patched in P1.1 emit a HashID
 * string for 'id', never a raw integer.
 *
 * Cheapest model to instantiate: PurchaseRequestItem
 * FK chain: roles → users → purchase_requests → purchase_request_items
 */
class ResourceHashIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_request_item_resource_emits_hashid_not_raw_integer(): void
    {
        // Role
        $roleId = DB::table('roles')->insertGetId([
            'name'       => 'Tester',
            'slug'       => 'tester',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // User (requested_by on purchase_requests)
        $userId = DB::table('users')->insertGetId([
            'name'       => 'PR Test User',
            'email'      => 'prtest@test.local',
            'password'   => bcrypt('Password1!'),
            'role_id'    => $roleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Purchase request
        $prId = DB::table('purchase_requests')->insertGetId([
            'pr_number'    => 'PR-TEST-0001',
            'requested_by' => $userId,
            'date'         => now()->toDateString(),
            'status'       => 'draft',
            'priority'     => 'normal',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // Purchase request item (cheapest: no item_id needed, it is nullable)
        $item = PurchaseRequestItem::create([
            'purchase_request_id' => $prId,
            'description'         => 'Test material',
            'quantity'            => '10.00',
            'unit'                => 'kg',
        ]);

        $arr = (new PurchaseRequestItemResource($item))->toArray(request());

        $this->assertArrayHasKey('id', $arr);
        $this->assertIsString($arr['id']);
        $this->assertFalse(ctype_digit((string) $arr['id']), 'id must be a hashid, not a raw integer');
    }
}
