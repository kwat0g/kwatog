<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Models\AuditLog;
use App\Common\Models\OutboxMessage;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Services\ReturnCaseService;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\SupplyChain\Services\DeliveryService;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Models\DeliveryProof;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use App\Modules\B2B\Services\CustomerPortalService;
use Tests\TestCase;

class CustomerPortalCombinedReceiptTest extends TestCase
{
    use RefreshDatabase;

    private CustomerPortalUser $portal;
    private Delivery $original;
    private Delivery $replacement;
    private ReturnCase $case;

    protected function refreshTestDatabase(): void
    {
        if (str_starts_with($this->name(), 'test_concurrent_')) {
            // Independent PostgreSQL connections must see committed fixture rows.
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
            $this->beforeApplicationDestroyed(static function (): void { RefreshDatabaseState::$migrated = false; });
            return;
        }
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            RefreshDatabaseState::$migrated = true;
        }
        $this->beginDatabaseTransaction();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
        $customer = Customer::factory()->create();
        $creator = User::factory()->create();
        $this->portal = CustomerPortalUser::create([
            'customer_id' => $customer->id, 'name' => 'Toyota receiver',
            'email' => Str::uuid().'@test.local', 'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $product = Product::factory()->create(['unit_of_measure' => 'pcs']);
        Item::factory()->create(['code' => $product->part_number, 'item_type' => 'finished_good', 'unit_of_measure' => 'pcs']);
        $order = SalesOrder::factory()->create([
            'customer_id' => $customer->id, 'created_by' => $creator->id, 'status' => 'confirmed',
            'subtotal' => '17500.00', 'vat_amount' => '2100.00', 'total_amount' => '19600.00',
        ]);
        $line = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => '500.00', 'quantity_delivered' => '0.00', 'unit_price' => '35.00', 'total' => '17500.00',
        ]);
        $this->original = $this->shipment($order, $line, $creator, ['2.000', '498.000'], '35.00');
        $this->case = new ReturnCase();
        $this->case->forceFill([
            'case_number' => 'CASE-'.Str::upper(Str::random(8)), 'type' => 'customer', 'status' => 'in_progress',
            'customer_id' => $customer->id, 'delivery_id' => $this->original->id, 'created_by' => $creator->id,
            'preferred_resolution' => 'redelivery', 'resolution' => 'redelivery', 'description' => 'Two damaged pieces',
        ])->save();
        $claim = $this->case->lines()->make();
        $claim->forceFill([
            'source_delivery_item_id' => $this->original->items()->latest('id')->first()->id,
            'product_id' => $product->id, 'description' => $product->name, 'unit' => 'pcs',
            'expected_quantity' => '498.000', 'received_quantity' => '498.000', 'missing_quantity' => '0.000',
            'defective_quantity' => '2.000', 'verified_missing_quantity' => '0.000', 'verified_defective_quantity' => '2.000',
            'source_unit_price' => '35.0000',
        ])->save();
        $replacementOrder = SalesOrder::factory()->create([
            'customer_id' => $customer->id, 'created_by' => $creator->id, 'status' => 'confirmed',
            'return_case_id' => $this->case->id, 'subtotal' => '0.00', 'vat_amount' => '0.00', 'total_amount' => '0.00',
        ]);
        $replacementLine = SalesOrderItem::factory()->create([
            'sales_order_id' => $replacementOrder->id, 'product_id' => $product->id,
            'quantity' => '2.00', 'quantity_delivered' => '0.00', 'unit_price' => '0.00', 'total' => '0.00',
        ]);
        $this->replacement = $this->shipment($replacementOrder, $replacementLine, $creator, ['2.000'], '0.00');
        $this->case->forceFill(['replacement_sales_order_id' => $replacementOrder->id])->save();
        $this->actingAs($this->portal, 'customer_portal');
    }

    private function shipment(SalesOrder $order, SalesOrderItem $line, User $creator, array $quantities, string $price): Delivery
    {
        $delivery = Delivery::create([
            'delivery_number' => 'DR-'.Str::upper(Str::random(8)), 'sales_order_id' => $order->id,
            'status' => 'delivered', 'scheduled_date' => now()->toDateString(), 'delivered_at' => now(), 'created_by' => $creator->id,
        ]);
        foreach ($quantities as $quantity) {
            DeliveryItem::create([
                'delivery_id' => $delivery->id, 'sales_order_item_id' => $line->id,
                'quantity' => $quantity, 'customer_received_quantity' => $quantity, 'unit_price' => $price,
            ]);
        }
        DeliveryProof::create([
            'delivery_id' => $delivery->id, 'proof_type' => 'signed_dr', 'file_name' => 'signed.pdf',
            'file_path' => 'deliveries/'.$delivery->id.'/signed.pdf', 'mime_type' => 'application/pdf', 'uploaded_by' => $creator->id,
        ]);
        return $delivery;
    }

    private function group(?Delivery $anchor = null): array
    {
        return $this->getJson('/api/v1/b2b/customer/deliveries/'.($anchor ?? $this->original)->hash_id.'/receipt-group')
            ->assertOk()->json('data');
    }

    private function payload(): array
    {
        $group = $this->group();
        return [
            'version' => $group['version'], 'receiver_name' => '  Toyota receiver  ',
            'shipments' => array_map(static fn (array $shipment) => [
                'delivery_id' => $shipment['delivery']['id'],
                'lines' => array_map(static fn (array $item) => [
                    'delivery_item_id' => $item['id'], 'received_quantity' => bcadd($item['quantity_delivered'], '0', 2),
                ], $shipment['delivery']['items']),
            ], $group['shipments']),
        ];
    }

    private function submit(array $payload)
    {
        return $this->postJson('/api/v1/b2b/customer/deliveries/'.$this->replacement->hash_id.'/confirm-group', $payload);
    }

    public function test_both_anchors_show_the_same_precise_group_without_side_effects(): void
    {
        $counts = [AuditLog::count(), Invoice::count(), OutboxMessage::count()];
        $group = $this->group();
        $this->assertSame($group, $this->group($this->replacement));
        $this->assertTrue($group['can_confirm']);
        $this->assertSame(['original', 'replacement'], array_column($group['shipments'], 'kind'));
        $this->assertCount(2, $group['shipments'][0]['delivery']['items']);
        $this->assertSame('2.000', $group['shipments'][1]['delivery']['items'][0]['quantity_delivered']);
        $this->assertSame($counts, [AuditLog::count(), Invoice::count(), OutboxMessage::count()]);
        $this->assertSame('in_progress', $this->case->fresh()->status->value);
    }

    public function test_one_submission_confirms_two_receipts_and_resolves_case_without_billing_replacement(): void
    {
        $payload = $this->payload();
        $this->submit($payload)->assertOk()->assertJsonPath('data.case.status', 'resolved');
        foreach ([$this->original, $this->replacement] as $delivery) {
            $this->assertSame('confirmed', $delivery->fresh()->status->value);
            $this->assertSame('Toyota receiver', $delivery->fresh()->receiver_name);
        }
        $this->assertSame('not_required', $this->replacement->fresh()->invoice_handoff_status->value);
        $this->assertDatabaseMissing('invoices', ['delivery_id' => $this->replacement->id]);
        $invoice = Invoice::where('delivery_id', $this->original->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('17500.00', $invoice->subtotal);
        $this->assertSame('500.00', bcadd($invoice->items()->sum('quantity'), '0', 2));
        $this->assertSame('500.00', $this->original->salesOrder->items()->first()->fresh()->quantity_delivered);
        $this->assertSame('2.00', $this->replacement->salesOrder->items()->first()->fresh()->quantity_delivered);
        $this->assertSame(2, AuditLog::where('action', 'customer.delivery.confirmed')->count());
        $counts = [AuditLog::count(), Invoice::count(), OutboxMessage::count()];
        $this->submit($payload)->assertOk();
        $this->assertSame($counts, [AuditLog::count(), Invoice::count(), OutboxMessage::count()]);
    }

    public function test_invalid_quantity_or_line_cannot_change_any_receipt(): void
    {
        $valid = $this->payload();
        foreach (['3', '-1', '1e3', '1.5', '1', '0.001', ''] as $quantity) {
            $payload = $valid;
            $payload['shipments'][1]['lines'][0]['received_quantity'] = $quantity;
            $this->submit($payload)->assertUnprocessable();
        }
        $payload = $valid;
        $payload['shipments'][1]['lines'][0]['delivery_item_id'] = $valid['shipments'][0]['lines'][0]['delivery_item_id'];
        $this->submit($payload)->assertUnprocessable();
        $payload = $valid;
        $payload['shipments'][] = $payload['shipments'][1];
        $this->submit($payload)->assertUnprocessable();
        $payload = $valid;
        $payload['shipments'][0]['lines'][1] = $payload['shipments'][0]['lines'][0];
        $this->submit($payload)->assertUnprocessable();
        $payload = $valid;
        unset($payload['shipments'][0]);
        $this->submit($payload)->assertUnprocessable();
        $this->assertSame('delivered', $this->original->fresh()->status->value);
        $this->assertSame('delivered', $this->replacement->fresh()->status->value);
        $this->assertSame('in_progress', $this->case->fresh()->status->value);
        $this->assertSame(0, AuditLog::where('action', 'customer.delivery.confirmed')->count());
    }

    public function test_missing_proof_and_short_replacement_block_group_without_blocking_discovery(): void
    {
        $this->replacement->proofs()->delete();
        $group = $this->group();
        $this->assertFalse($group['can_confirm']);
        $this->assertStringContainsString('proof', $group['blocked_reason']);
        $this->submit($this->payload())->assertUnprocessable();
        $this->replacement->items()->update(['customer_received_quantity' => '1.000']);
        $this->assertFalse($this->group()['can_confirm']);
    }

    public function test_foreign_customer_and_unknown_payload_keys_are_rejected(): void
    {
        $payload = $this->payload();
        $payload['accept_rejection'] = true;
        $this->submit($payload)->assertUnprocessable();
        $other = CustomerPortalUser::create([
            'customer_id' => Customer::factory()->create()->id, 'name' => 'Other', 'email' => Str::uuid().'@test.local',
            'password' => bcrypt('Password1!'), 'is_active' => true,
        ]);
        $this->actingAs($other, 'customer_portal');
        $this->getJson('/api/v1/b2b/customer/deliveries/'.$this->original->hash_id.'/receipt-group')->assertNotFound();
        unset($payload['accept_rejection']);
        $this->submit($payload)->assertNotFound();
    }

    public function test_stale_snapshot_and_confirmed_context_are_validated_without_overwriting_receiver(): void
    {
        $payload = $this->payload();
        $this->replacement->update(['status' => 'confirmed', 'receiver_name' => 'Earlier receiver', 'confirmed_at' => now()]);
        $this->submit($payload)->assertUnprocessable()->assertJsonValidationErrors('version');
        app(ReturnCaseService::class)->synchronizeReplacement($this->case->id);
        $payload = $this->payload();
        $this->submit($payload)->assertOk();
        $this->assertSame('Earlier receiver', $this->replacement->fresh()->receiver_name);
        $payload['shipments'][1]['lines'][0]['received_quantity'] = '200';
        $this->submit($payload)->assertUnprocessable();
    }

    public function test_unrelated_owned_delivery_cannot_be_injected_into_group(): void
    {
        $unrelatedOrder = SalesOrder::factory()->create(['customer_id' => $this->portal->customer_id]);
        $line = SalesOrderItem::factory()->create(['sales_order_id' => $unrelatedOrder->id]);
        $unrelated = $this->shipment($unrelatedOrder, $line, User::factory()->create(), ['2.000'], '0.00');
        $this->getJson('/api/v1/b2b/customer/deliveries/'.$unrelated->hash_id.'/receipt-group')->assertOk()->assertJsonPath('data', null);
        $payload = $this->payload();
        $payload['shipments'][1]['delivery_id'] = $unrelated->hash_id;
        $this->submit($payload)->assertUnprocessable();
        $this->assertSame('delivered', $unrelated->fresh()->status->value);
    }

    public function test_another_open_case_blocks_group_but_replacement_can_still_confirm_alone(): void
    {
        $another = $this->case->replicate();
        $another->forceFill(['case_number' => 'CASE-'.Str::random(8), 'replacement_sales_order_id' => null, 'status' => 'submitted'])->save();
        $line = $this->case->lines()->first()->replicate();
        $line->forceFill(['return_case_id' => $another->id])->save();
        $this->assertFalse($this->group()['can_confirm']);
        $this->submit($this->payload())->assertUnprocessable();
        $replacement = $this->group()['shipments'][1]['delivery'];
        $this->postJson('/api/v1/b2b/customer/deliveries/'.$this->replacement->hash_id.'/confirm', [
            'receiver_name' => 'Toyota receiver',
            'lines' => array_map(fn ($item) => ['delivery_item_id' => $item['id'], 'received_quantity' => '2'], $replacement['items']),
        ])->assertOk();
        $this->assertSame('delivered', $this->original->fresh()->status->value);
    }

    public function test_split_replacement_shipments_cover_the_claim_without_merging_original_lines(): void
    {
        $this->replacement->items()->update(['quantity' => '1.000', 'customer_received_quantity' => '1.000']);
        $this->assertFalse($this->group()['can_confirm']);
        $extra = $this->shipment($this->replacement->salesOrder, $this->replacement->salesOrder->items()->first(), User::factory()->create(), ['1.000'], '0.00');
        $this->assertCount(3, $this->group()['shipments']);
        $this->submit($this->payload())->assertOk();
        $this->assertSame('confirmed', $extra->fresh()->status->value);
        $this->assertSame('resolved', $this->case->fresh()->status->value);
    }

    public function test_group_waits_for_linked_physical_return_completion_and_coverage(): void
    {
        $return = ReturnRequest::create([
            'rma_number' => 'RMA-'.Str::random(8), 'type' => 'customer_return', 'customer_id' => $this->portal->customer_id,
            'status' => 'approved', 'reason_description' => 'Collect damaged pieces', 'created_by' => $this->case->created_by,
        ]);
        $this->case->forceFill(['return_request_id' => $return->id])->save();
        $this->assertFalse($this->group()['can_confirm']);
        $this->submit($this->payload())->assertUnprocessable();
        $return->update(['status' => 'completed']);
        $this->assertFalse($this->group()['can_confirm']);
        $this->assertSame('delivered', $this->replacement->fresh()->status->value);
        $claim = $this->case->lines()->first();
        $return->items()->create([
            'source_delivery_item_id' => $claim->source_delivery_item_id, 'product_id' => $claim->product_id,
            'quantity' => '2.000', 'returned_quantity' => '2.000', 'unit_price' => '35.00', 'total' => '70.00',
        ]);
        $this->assertTrue($this->group()['can_confirm']);
        $this->submit($this->payload())->assertOk();
    }

    public function test_failure_after_replacement_confirmation_rolls_back_all_business_effects(): void
    {
        $payload = $this->payload();
        $counts = [AuditLog::count(), Invoice::count(), OutboxMessage::count()];
        $real = app(DeliveryService::class);
        $originalId = $this->original->id;
        $this->mock(DeliveryService::class, function ($mock) use ($real, $originalId): void {
            $mock->shouldReceive('confirm')->andReturnUsing(function ($delivery, $by, $receiver) use ($real, $originalId) {
                if ($delivery->id === $originalId) {
                    throw new BusinessRuleException('Original confirmation failed.');
                }
                return $real->confirm($delivery, $by, $receiver);
            });
        });
        $this->submit($payload)->assertUnprocessable();
        $this->assertSame('delivered', $this->original->fresh()->status->value);
        $this->assertSame('delivered', $this->replacement->fresh()->status->value);
        $this->assertSame('in_progress', $this->case->fresh()->status->value);
        $this->assertSame($counts, [AuditLog::count(), Invoice::count(), OutboxMessage::count()]);
    }

    public function test_wrong_product_and_nonzero_replacement_price_cannot_settle_the_case(): void
    {
        $line = $this->replacement->salesOrder->items()->first();
        $line->update(['unit_price' => '1.00']);
        $this->assertFalse($this->group()['can_confirm']);
        $this->submit($this->payload())->assertUnprocessable();
        $line->update(['unit_price' => '0.00', 'product_id' => Product::factory()->create()->id]);
        $this->assertFalse($this->group()['can_confirm']);
        $this->submit($this->payload())->assertUnprocessable();
        $this->assertSame('delivered', $this->replacement->fresh()->status->value);
        $this->assertSame('in_progress', $this->case->fresh()->status->value);
    }

    public function test_concurrent_single_receipt_and_group_do_not_duplicate_confirmation(): void
    {
        $this->raceReceipt('single');
    }

    public function test_concurrent_case_update_and_group_finish_without_deadlock(): void
    {
        $this->raceReceipt('case');
    }

    private function raceReceipt(string $mode): void
    {
        $payload = $this->payload();
        $output = tempnam(sys_get_temp_dir(), 'receipt-race-');
        DB::purge();
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('Could not start receipt concurrency worker.');
        }
        if ($pid === 0) {
            try {
                DB::statement("SET statement_timeout = '12000ms'");
                if ($mode === 'single') {
                    app(CustomerPortalService::class)->confirmDelivery($this->portal->customer_id, $this->replacement,
                        ['receiver_name' => 'Concurrent receiver', 'lines' => $payload['shipments'][1]['lines']], $this->portal);
                } else {
                    $user = User::findOrFail($this->case->created_by);
                    app(ReturnCaseService::class)->act($this->case, ['action' => 'reply', 'message' => 'Concurrent case review.'], $user,
                        ['type' => 'internal', 'id' => $user->id, 'name' => $user->name]);
                }
                file_put_contents($output, 'ok');
            } catch (\Throwable $error) {
                file_put_contents($output, get_class($error).': '.$error->getMessage());
            }
            exit(0);
        }
        try {
            DB::statement("SET statement_timeout = '12000ms'");
            $response = $this->submit($payload);
            $this->assertContains($response->status(), [200, 422]);
            pcntl_waitpid($pid, $status);
            $childResult = file_get_contents($output);
            $this->assertTrue($childResult === 'ok' || str_contains($childResult, 'Shipment details changed'), $childResult);
            if ($response->status() === 422) {
                $this->submit($this->payload())->assertOk();
            }
            $this->assertSame('confirmed', $this->original->fresh()->status->value);
            $this->assertSame('confirmed', $this->replacement->fresh()->status->value);
            $this->assertSame('resolved', $this->case->fresh()->status->value);
            $this->assertSame(2, AuditLog::where('action', 'customer.delivery.confirmed')->count());
            $this->assertLessThanOrEqual(1, Invoice::where('delivery_id', $this->original->id)->count());
            $this->assertDatabaseMissing('invoices', ['delivery_id' => $this->replacement->id]);
        } finally {
            unlink($output);
            DB::statement('SET statement_timeout = 0');
        }
    }
}
