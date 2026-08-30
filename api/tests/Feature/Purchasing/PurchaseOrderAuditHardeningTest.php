<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M037 audit (2026-08-30). Four contained defects measured against real
 * PostgreSQL rows during the purchase-order re-audit.
 */
class PurchaseOrderAuditHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
    }

    private function payload(array $lineOverrides = []): array
    {
        $pr = PurchaseRequest::factory()->create();
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        return [
            'vendor_id' => Vendor::factory()->create()->hash_id,
            'purchase_request_id' => $pr->hash_id,
            'date' => '2026-08-30',
            'items' => [array_merge([
                'item_id' => Item::factory()->create()->hash_id,
                'description' => 'Audit line',
                'quantity' => '3',
                'unit' => 'pcs',
                'unit_price' => '100.00',
            ], $lineOverrides)],
        ];
    }

    /**
     * A bill whose three-way match is BLOCKED must not read as "matched" on the
     * purchase-order payload. show() projected `bills` to six columns while
     * PurchaseOrderResource read four more off it; because
     * preventAccessingMissingAttributes() is deliberately off, the unselected
     * columns came back null and `(bool) null` rendered a green "Matched" chip
     * over a genuine variance.
     */
    public function test_bill_variance_flags_survive_the_purchase_order_projection(): void
    {
        $po = PurchaseOrder::factory()->create();
        $po->forceFill(['status' => PurchaseOrderStatus::Sent->value])->save();
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => Item::factory()->create()->id,
            'description' => 'line', 'unit' => 'pcs',
            'quantity' => '10', 'unit_price' => '100.00', 'total' => '1000.00',
        ]);
        $bill = Bill::factory()->create([
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'total_amount' => '2000.00',
        ]);
        DB::table('bills')->where('id', $bill->id)->update([
            'has_variances' => true,
            'three_way_overridden' => false,
            'three_way_match_snapshot' => json_encode(['overall_status' => 'blocked']),
            'due_date' => '2026-09-30',
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/purchasing/purchase-orders/'.$po->hash_id)
            ->assertOk()
            ->assertJsonPath('data.bills.0.has_variances', true)
            ->assertJsonPath('data.bills.0.three_way_overridden', false)
            ->assertJsonPath('data.bills.0.due_date', '2026-09-30')
            ->assertJsonPath('data.bills.0.three_way_review_status', 'manual_review');
    }

    /**
     * quantity and unit_price land in decimal(15,2) and are multiplied into a
     * decimal(15,2) line total. `decimal:0,2` alone bounded neither, so an
     * oversized figure reached PostgreSQL and surfaced as SQLSTATE[22003] — a
     * 500 with the SQL statement in the body — instead of a 422.
     */
    public function test_oversized_money_is_a_validation_error_not_a_database_overflow(): void
    {
        $admin = $this->admin();

        foreach (['100000000000000000', '999999999999999.99'] as $overflow) {
            $this->actingAs($admin)
                ->postJson('/api/v1/purchasing/purchase-orders', $this->payload(['unit_price' => $overflow]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('items.0.unit_price');
        }

        $this->actingAs($admin)
            ->postJson('/api/v1/purchasing/purchase-orders', $this->payload(['quantity' => '100000000000000000']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        // The pre-existing decimal:0,2 guards must keep working.
        $this->actingAs($admin)
            ->postJson('/api/v1/purchasing/purchase-orders', $this->payload(['unit_price' => '1.999']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.unit_price');
        $this->actingAs($admin)
            ->postJson('/api/v1/purchasing/purchase-orders', $this->payload(['unit_price' => '1e3']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.unit_price');

        // A realistic amount still posts.
        $this->actingAs($admin)
            ->postJson('/api/v1/purchasing/purchase-orders', $this->payload(['unit_price' => '9999999.99']))
            ->assertStatus(201);
    }

    /**
     * Cancelling an already-cancelled PO used to succeed, appending a second
     * "Cancelled: …" block to remarks and recording a duplicate
     * PurchaseOrderCancelled message on the p2p outbox for one logical event.
     */
    public function test_cancelling_an_already_cancelled_purchase_order_is_refused(): void
    {
        $svc = app(PurchaseOrderService::class);
        $po = PurchaseOrder::factory()->create();
        $po->forceFill(['status' => PurchaseOrderStatus::Draft->value])->save();
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id, 'item_id' => Item::factory()->create()->id,
            'description' => 'line', 'unit' => 'pcs',
            'quantity' => '1', 'unit_price' => '1.00', 'total' => '1.00',
        ]);

        $svc->cancel($po->fresh(), 'first cancellation reason');
        $remarksAfterFirst = $po->fresh()->remarks;

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('already cancelled');
        try {
            $svc->cancel($po->fresh(), 'second cancellation reason');
        } finally {
            $this->assertSame(
                $remarksAfterFirst,
                $po->fresh()->remarks,
                'a refused second cancellation must not append to remarks',
            );
        }
    }

    /** Error bodies must name records, never expose a raw integer primary key. */
    public function test_conversion_errors_do_not_leak_raw_primary_keys(): void
    {
        $svc = app(PurchaseOrderService::class);
        $pr = PurchaseRequest::factory()->create();
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();
        $line = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => Item::factory()->create()->id,
            'description' => 'Polypropylene resin',
            'quantity' => '5',
            'unit' => 'kg',
            'estimated_unit_price' => '250.00',
        ]);

        try {
            // Empty vendor map -> the "no vendor assignment" branch.
            $svc->convertFromPr($pr->fresh(), [], $this->admin());
            $this->fail('conversion without a vendor map should have been refused');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('Polypropylene resin', $e->getMessage());
            $this->assertStringNotContainsString("line {$line->id} ", $e->getMessage());
            $this->assertDoesNotMatchRegularExpression(
                '/\bline \d+\b/',
                $e->getMessage(),
                'the refusal must name the PR line, not its primary key',
            );
        }
    }
}
