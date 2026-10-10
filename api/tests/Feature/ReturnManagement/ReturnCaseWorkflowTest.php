<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\Item;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnCaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    private function source(Customer $customer, int $lines = 2): array
    {
        $user = User::factory()->create();
        $order = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => SalesOrderStatus::PartiallyDelivered->value,
            'created_by' => $user->id,
        ]);
        $delivery = Delivery::create([
            'delivery_number' => 'DEL-CASE-'.Str::upper(Str::random(8)),
            'sales_order_id' => $order->id,
            'status' => 'confirmed',
            'scheduled_date' => now()->toDateString(),
            'delivered_at' => now(),
            'created_by' => $user->id,
        ]);
        $items = collect();
        for ($i = 1; $i <= $lines; $i++) {
            $product = Product::create(['part_number' => 'CASE-P-'.$i.'-'.Str::random(4), 'name' => 'Case product '.$i]);
            Item::factory()->create([
                'code' => $product->part_number,
                'name' => $product->name,
                'item_type' => ItemType::FinishedGood->value,
                'unit_of_measure' => 'pcs',
                'is_active' => true,
            ]);
            $orderLine = SalesOrderItem::factory()->create([
                'sales_order_id' => $order->id, 'product_id' => $product->id,
                'quantity' => 10, 'quantity_delivered' => 10, 'unit_price' => 25,
            ]);
            $items->push(DeliveryItem::create([
                'delivery_id' => $delivery->id, 'sales_order_item_id' => $orderLine->id,
                'quantity' => 10, 'unit_price' => 25,
            ]));
        }

        return [$delivery->fresh()->load('items.salesOrderItem.product'), $items];
    }

    private function portal(Customer $customer): CustomerPortalUser
    {
        return CustomerPortalUser::create([
            'customer_id' => $customer->id, 'name' => 'Case Customer',
            'email' => 'case-'.Str::random(8).'@test.local', 'password' => bcrypt('Password1!'),
            'is_active' => true, 'password_changed_at' => now(),
        ]);
    }

    private function payload(Delivery $delivery, $items, string $key): array
    {
        return [
            'source_kind' => 'delivery', 'source_id' => $delivery->hash_id,
            'description' => 'Two-line delivery discrepancy', 'preferred_resolution' => 'credit',
            'request_key' => $key,
            'lines' => $items->map(fn (DeliveryItem $line, int $index) => [
                'source_line_id' => $line->hash_id, 'received_quantity' => $index === 0 ? '9.000' : '8.000',
                'defective_quantity' => $index === 0 ? '2.000' : '0.000',
                'reason' => 'Damaged in transit',
            ])->all(),
        ];
    }

    public function test_customer_can_submit_multiline_case_and_retry_is_idempotent(): void
    {
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer);
        $user = $this->portal($customer);
        $payload = $this->payload($delivery, $items, (string) Str::uuid());

        $first = $this->actingAs($user, 'customer_portal')->postJson('/api/v1/b2b/customer/problems', $payload)->assertCreated();
        $second = $this->actingAs($user, 'customer_portal')->postJson('/api/v1/b2b/customer/problems', $payload)->assertCreated();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ReturnCase::query()->where('customer_id', $customer->id)->count());
        $this->assertCount(2, $first->json('data.lines'));
    }

    public function test_customer_intake_rejects_quantity_and_source_manipulation_without_creating_records(): void
    {
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer, 1);
        $delivery->update(['status' => 'delivered']);
        $this->actingAs($this->portal($customer), 'customer_portal');
        $base = $this->payload($delivery, $items, (string) Str::uuid());
        foreach ([
            ['received_quantity', '11', 'received_quantity'],
            ['received_quantity', '-1', 'received_quantity'],
            ['received_quantity', '1e2', 'received_quantity'],
            ['received_quantity', '9.5', 'received_quantity'],
            ['defective_quantity', '10', 'defective_quantity'],
            ['defective_quantity', '0.5', 'defective_quantity'],
            ['expected_quantity', '20', 'expected_quantity'],
        ] as [$field, $quantity, $error]) {
            $payload = $base;
            $payload['request_key'] = (string) Str::uuid();
            $payload['lines'][0][$field] = $quantity;
            $this->postJson('/api/v1/b2b/customer/problems', $payload)->assertUnprocessable()
                ->assertJsonValidationErrors('lines.0.'.$error);
        }
        $payload = $base;
        $payload['lines'][0]['source_unit_price'] = '999999';
        $this->postJson('/api/v1/b2b/customer/problems', $payload)->assertUnprocessable()->assertJsonValidationErrors('lines.0');
        $payload = $base;
        $payload['lines'][] = $payload['lines'][0];
        $this->postJson('/api/v1/b2b/customer/problems', $payload)->assertUnprocessable()->assertJsonValidationErrors('lines.0.source_line_id');
        [$otherDelivery, $otherItems] = $this->source(Customer::factory()->create(), 1);
        $payload = $base;
        $payload['lines'][0]['source_line_id'] = $otherItems->first()->hash_id;
        $this->postJson('/api/v1/b2b/customer/problems', $payload)->assertUnprocessable()->assertJsonValidationErrors('lines.0.source_line_id');
        $this->assertDatabaseCount('return_cases', 0);
        $this->assertDatabaseCount('return_case_lines', 0);
        $this->assertDatabaseCount('return_requests', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertNull($delivery->fresh()->invoice_id);
    }

    public function test_report_cannot_change_known_lot_provenance(): void
    {
        [$delivery, $items] = $this->source(Customer::factory()->create(), 1);
        $service = \Mockery::mock(\App\Modules\ReturnManagement\Services\ReturnCaseSourceService::class)->makePartial();
        $options = app(\App\Modules\ReturnManagement\Services\ReturnCaseSourceService::class)->options($delivery);
        $options['lines'][0]['lot_number'] = 'REAL-DISPATCH-LOT';
        $service->shouldReceive('options')->andReturn($options);
        $payload = $this->payload($delivery, $items, (string) Str::uuid());
        $payload['lines'][0]['lot_number'] = 'FAKE-LOT';
        try {
            $service->prepareLines($delivery, $payload['lines'], false, true);
            $this->fail('Changed source lot was accepted.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('lines.0.lot_number', $error->errors());
        }
        $this->assertDatabaseCount('return_cases', 0);
    }

    public function test_mixed_shortage_cannot_agree_to_physical_return_only(): void
    {
        [$delivery, $items] = $this->source(Customer::factory()->create(), 1);
        $manager = User::factory()->create(['role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id')]);
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $this->payload($delivery, $items, (string) Str::uuid()))->assertCreated()->json('data.id');
        $path = '/api/v1/return-management/cases/'.$case.'/actions';
        $this->postJson($path, ['action' => 'agree', 'resolution' => 'return_goods', 'message' => 'Return damaged goods.'])
            ->assertUnprocessable()->assertJsonValidationErrors('resolution');
        $lineId = $this->getJson('/api/v1/return-management/cases/'.$case)->assertOk()->assertJsonPath('data.status', 'submitted')->json('data.lines.0.id');
        $this->postJson($path, ['action' => 'agree', 'resolution' => 'redelivery', 'message' => 'Nothing affected after review.',
            'lines' => [['id' => $lineId, 'verified_missing_quantity' => '0', 'verified_defective_quantity' => '0']],
        ])->assertUnprocessable()->assertJsonValidationErrors('resolution');
        $this->postJson($path, ['action' => 'agree', 'resolution' => 'credit', 'message' => 'Credit the verified missing and defective quantities.'])
            ->assertOk()->assertJsonPath('data.resolution', 'credit');
        $this->assertDatabaseCount('return_requests', 0);
    }

    public function test_fractional_claim_is_preserved_without_an_unfulfillable_replacement_agreement(): void
    {
        [$delivery, $items] = $this->source(Customer::factory()->create(), 1);
        $manager = User::factory()->create(['role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id')]);
        $payload = $this->payload($delivery, $items, (string) Str::uuid());
        $payload['lines'][0]['received_quantity'] = '9.999';
        $payload['lines'][0]['defective_quantity'] = '0';
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/return-management/cases/'.$case.'/actions', [
            'action' => 'agree', 'resolution' => 'redelivery', 'message' => 'Replace the missing fraction.',
        ])->assertUnprocessable()->assertJsonValidationErrors('resolution');
        $this->getJson('/api/v1/return-management/cases/'.$case)->assertOk()
            ->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.lines.0.missing_quantity', '0.001')
            ->assertJsonPath('data.lines.0.verified_missing_quantity', null);
    }

    public function test_verification_cannot_add_an_unreported_category_or_exceed_its_reported_limit(): void
    {
        [$delivery, $items] = $this->source(Customer::factory()->create(), 1);
        $manager = User::factory()->create(['role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id')]);
        foreach ([['10', '2'], ['8', '0']] as [$received, $damaged]) {
            $payload = $this->payload($delivery, $items, (string) Str::uuid());
            $payload['lines'][0]['received_quantity'] = $received;
            $payload['lines'][0]['defective_quantity'] = $damaged;
            $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $payload)->assertCreated()->json('data');
            $path = '/api/v1/return-management/cases/'.$case['id'].'/actions';
            $this->postJson($path, ['action' => 'start_review'])->assertOk();
            foreach ([['1', '3'], ['4242.000', '323232'], ['-1', '0'], ['1e2', '0'], ['0', '0.0001']] as [$missing, $defective]) {
                $this->postJson($path, [
                    'action' => 'agree', 'resolution' => 'redelivery', 'message' => 'Verify the reported goods.',
                    'lines' => [['id' => $case['lines'][0]['id'], 'verified_missing_quantity' => $missing, 'verified_defective_quantity' => $defective]],
                ])->assertUnprocessable();
            }
            $this->getJson('/api/v1/return-management/cases/'.$case['id'])->assertOk()
                ->assertJsonPath('data.status', 'under_review')
                ->assertJsonPath('data.lines.0.verified_missing_quantity', null)
                ->assertJsonPath('data.lines.0.verified_defective_quantity', null);
        }
        $this->assertDatabaseCount('return_requests', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_customer_cannot_read_or_attach_evidence_to_another_customer_case(): void
    {
        $owner = Customer::factory()->create();
        [$delivery, $items] = $this->source($owner);
        $case = $this->actingAs($this->portal($owner), 'customer_portal')
            ->postJson('/api/v1/b2b/customer/problems', $this->payload($delivery, $items, (string) Str::uuid()))
            ->assertCreated()->json('data.id');

        $other = $this->portal(Customer::factory()->create());
        $this->actingAs($other, 'customer_portal')->getJson('/api/v1/b2b/customer/problems/'.$case)->assertForbidden();
        $this->actingAs($other, 'customer_portal')->postJson('/api/v1/b2b/customer/problems/'.$case.'/attachments', [
            'file' => UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_internal_manager_can_verify_case_and_request_info_then_resume_review(): void
    {
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer);
        $manager = User::factory()->create(['role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id')]);
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $this->payload($delivery, $items, (string) Str::uuid()))
            ->assertCreated()->json('data');

        $id = $case['id'];
        $this->actingAs($manager)->postJson('/api/v1/return-management/cases/'.$id.'/actions', [
            'action' => 'request_info', 'message' => 'Please confirm the damaged quantity.',
        ])->assertOk()->assertJsonPath('data.status', 'information_needed');
        $this->actingAs($manager)->postJson('/api/v1/return-management/cases/'.$id.'/actions', [
            'action' => 'reply', 'message' => 'Confirmed two damaged units.',
        ])->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->actingAs($manager)->postJson('/api/v1/return-management/cases/'.$id.'/actions', [
            'action' => 'agree', 'resolution' => 'no_action', 'message' => 'Reviewed and accepted as a documented variance.',
        ])->assertOk()->assertJsonPath('data.status', 'action_agreed');
    }
    public function test_case_and_legacy_return_share_the_delivery_quantity_limit(): void
    {
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer, 1);
        $payload = $this->payload($delivery, $items, (string) Str::uuid());
        $payload['lines'][0]['received_quantity'] = '5';
        $payload['lines'][0]['defective_quantity'] = '2';
        $portal = $this->portal($customer);
        $this->actingAs($portal, 'customer_portal')->postJson('/api/v1/b2b/customer/problems', $payload)->assertCreated();
        $this->postJson('/api/v1/b2b/customer/return-requests', [
            'items' => [['source_delivery_item_id' => $items->first()->hash_id, 'quantity' => '4']],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('return_requests', 0);
        $this->assertDatabaseCount('stock_movements', 0);
        $payload['request_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/b2b/customer/problems', $payload)->assertCreated();
        $this->assertDatabaseCount('return_cases', 1);
        $payload['description'] = 'A different report that still exceeds the shared quantity.';
        $payload['request_key'] = (string) Str::uuid();
        $this->postJson('/api/v1/b2b/customer/problems', $payload)->assertUnprocessable();
    }

    public function test_case_handoff_does_not_count_the_same_defects_twice(): void
    {
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer, 1);
        $manager = User::factory()->create(['role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id')]);
        $payload = $this->payload($delivery, $items, (string) Str::uuid());
        $payload['lines'][0]['received_quantity'] = '10';
        $payload['lines'][0]['defective_quantity'] = '10';
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $payload)->assertCreated()->json('data');
        $path = '/api/v1/return-management/cases/'.$case['id'].'/actions';
        $this->postJson($path, ['action' => 'agree', 'resolution' => 'return_goods', 'message' => 'Return the ten defective parts for inspection.'])->assertOk();
        $rma = $this->postJson($path, ['action' => 'create_return'])->assertOk()->json('data.return_request.id');
        $this->postJson($path, ['action' => 'create_return'])->assertOk()->assertJsonPath('data.return_request.id', $rma);
        $this->assertDatabaseCount('return_requests', 1);
        $this->postJson($path, ['action' => 'request_info', 'message' => 'Please send the collection reference.'])->assertOk();
        $this->postJson($path, ['action' => 'reply', 'message' => 'Reference attached.'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson($path, ['action' => 'resolve'])->assertUnprocessable();
    }

    public function test_no_charge_replacement_requires_approval_and_cannot_be_invoiced(): void
    {
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer, 1);
        $manager = User::factory()->create(['role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id')]);
        $payload = $this->payload($delivery, $items, (string) Str::uuid());
        $payload['lines'][0]['received_quantity'] = '0';
        $payload['lines'][0]['defective_quantity'] = '0';
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $payload)->assertCreated()->json('data');
        $path = '/api/v1/return-management/cases/'.$case['id'].'/actions';
        $this->postJson($path, ['action' => 'agree', 'resolution' => 'redelivery', 'message' => 'Send the ten missing parts without a second charge.'])->assertOk();
        $unrelated = SalesOrder::factory()->create(['customer_id' => $customer->id, 'created_by' => $manager->id]);
        $this->postJson($path, ['action' => 'link_resolution', 'replacement_sales_order_id' => $unrelated->hash_id])->assertUnprocessable();
        $this->getJson('/api/v1/return-management/cases/'.$case['id'])->assertOk()->assertJsonPath('data.replacement_order', null);
        $this->postJson($path, ['action' => 'create_replacement'])->assertForbidden();
        $checker = User::factory()->create(['role_id' => Role::query()->where('slug', 'finance_officer')->value('id')]);
        $response = $this->actingAs($checker)->postJson($path, ['action' => 'create_replacement'])->assertOk();
        $orderId = $response->json('data.replacement_order.id');
        $response->assertJsonPath('data.replacement_order.status', 'confirmed');
        $this->postJson($path, ['action' => 'link_resolution', 'replacement_sales_order_id' => $orderId])->assertOk();
        $this->postJson($path, ['action' => 'create_replacement'])->assertOk()->assertJsonPath('data.replacement_order.id', $orderId);
        $replacement = SalesOrder::query()->whereNotNull('return_case_id')->firstOrFail();
        $this->assertSame('0.00', $replacement->total_amount);
        $this->assertSame('10.00', $replacement->items()->firstOrFail()->quantity);
        $this->postJson($path, ['action' => 'resolve'])->assertUnprocessable();
        // Normal delivery controls own physical dispatch. Represent two confirmed
        // installments here and exercise the case/billing handoff on their documents.
        foreach ([1, 2] as $installment) {
            $shipment = Delivery::create([
                'delivery_number' => 'DR-REPL-'.Str::random(6), 'sales_order_id' => $replacement->id,
                'status' => 'confirmed', 'scheduled_date' => now()->toDateString(), 'created_by' => $checker->id,
            ]);
            $shipment->items()->create(['sales_order_item_id' => $replacement->items()->firstOrFail()->id,
                'quantity' => '5', 'unit_price' => '0']);
            $result = app(\App\Modules\SupplyChain\Services\DeliveryService::class)->retryInvoiceHandoff($shipment, $checker);
            $this->assertSame('not_required', $result->invoice_handoff_status->value);
            event(new \App\Modules\SupplyChain\Events\DeliveryConfirmed($shipment));
            $this->getJson('/api/v1/return-management/cases/'.$case['id'])
                ->assertOk()->assertJsonPath('data.status', $installment === 1 ? 'in_progress' : 'resolved')
                ->assertJsonPath('data.lines.0.redelivered_quantity', $installment === 1 ? '5.000' : '10.000')
                ->assertJsonPath('data.lines.0.remaining_redelivery_quantity', $installment === 1 ? '5.000' : '0.000');
            event(new \App\Modules\SupplyChain\Events\DeliveryConfirmed($shipment));
        }
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, \App\Modules\ReturnManagement\Models\ReturnCaseEvent::query()->where('return_case_id', $replacement->return_case_id)->where('action', 'resolve')->count());
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('no-charge replacement order');
        app(\App\Modules\Accounting\Services\InvoiceService::class)->create([
            'customer_id' => $customer->hash_id, 'sales_order_id' => $replacement->hash_id,
            'date' => now()->toDateString(), 'is_vatable' => false,
            'items' => [['revenue_account_id' => \App\Modules\Accounting\Models\Account::query()->where('code', '4010')->value('id'),
                'description' => 'Replacement', 'quantity' => '10', 'unit_price' => '25']],
        ], $checker);
    }

    public function test_automatic_replacement_closure_waits_for_receipt_and_complete_physical_return(): void
    {
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer, 2);
        $items[1]->salesOrderItem->update(['product_id' => $items[0]->salesOrderItem->product_id]);
        $manager = User::factory()->create(['role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id')]);
        $payload = $this->payload($delivery, $items, (string) Str::uuid());
        $payload['lines'][0]['received_quantity'] = '10';
        $payload['lines'][0]['defective_quantity'] = '1';
        $payload['lines'][1]['received_quantity'] = '10';
        $payload['lines'][1]['defective_quantity'] = '2';
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $payload)->assertCreated()->json('data');
        $path = '/api/v1/return-management/cases/'.$case['id'];
        $this->postJson($path.'/actions', ['action' => 'agree', 'resolution' => 'redelivery', 'message' => 'Replace and collect all three damaged pieces.'])->assertOk();
        $checker = User::factory()->create(['role_id' => Role::query()->where('slug', 'finance_officer')->value('id')]);
        $this->actingAs($checker)->postJson($path.'/actions', ['action' => 'create_replacement'])->assertOk();
        $replacement = SalesOrder::query()->where('return_case_id', ReturnCase::tryDecodeHash($case['id']))->firstOrFail();
        $this->postJson($path.'/actions', ['action' => 'create_return'])->assertOk();
        $stored = ReturnCase::findOrFail($replacement->return_case_id);
        $return = $stored->returnRequest;
        $shipment = Delivery::create([
            'delivery_number' => 'DR-COLLECT-'.Str::random(6), 'sales_order_id' => $replacement->id,
            'status' => 'delivered', 'scheduled_date' => now()->toDateString(), 'created_by' => $checker->id,
        ]);
        $shipment->items()->create(['sales_order_item_id' => $replacement->items()->firstOrFail()->id, 'quantity' => '3', 'unit_price' => '0']);
        event(new \App\Modules\SupplyChain\Events\DeliveryConfirmed($delivery));
        event(new \App\Modules\SupplyChain\Events\DeliveryConfirmed($shipment));
        $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.lines.0.redelivered_quantity', '0.000');
        $shipment->update(['status' => 'confirmed']);
        event(new \App\Modules\SupplyChain\Events\DeliveryConfirmed($shipment));
        $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.lines.0.redelivered_quantity', '1.000')->assertJsonPath('data.lines.1.redelivered_quantity', '2.000');
        $return->update(['status' => 'completed']);
        event(new \App\Modules\ReturnManagement\Events\ReturnRequestUpdated($return));
        $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'in_progress');
        foreach ($return->items as $item) {
            $item->update(['returned_quantity' => $item->quantity]);
        }
        event(new \App\Modules\ReturnManagement\Events\ReturnRequestUpdated($return));
        $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'resolved');
        event(new \App\Modules\ReturnManagement\Events\ReturnRequestUpdated($return));
        $this->assertSame(1, $stored->events()->where('action', 'resolve')->count());
    }

    public function test_replacement_approval_rolls_back_when_normal_confirmation_rejects_inactive_product(): void
    {
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer, 1);
        $manager = User::factory()->create(['role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id')]);
        $payload = $this->payload($delivery, $items, (string) Str::uuid());
        $payload['lines'][0]['received_quantity'] = '0';
        $payload['lines'][0]['defective_quantity'] = '0';
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $payload)->assertCreated()->json('data');
        $path = '/api/v1/return-management/cases/'.$case['id'];
        $this->postJson($path.'/actions', ['action' => 'agree', 'resolution' => 'redelivery', 'message' => 'Replace verified missing pieces.'])->assertOk();
        $items[0]->salesOrderItem->product->update(['is_active' => false]);
        $checker = User::factory()->create(['role_id' => Role::query()->where('slug', 'finance_officer')->value('id')]);
        $this->actingAs($checker)->postJson($path.'/actions', ['action' => 'create_replacement'])->assertUnprocessable();
        $this->getJson($path)->assertOk()->assertJsonPath('data.status', 'action_agreed')->assertJsonPath('data.replacement_order', null);
        $this->assertFalse(SalesOrder::query()->where('return_case_id', ReturnCase::tryDecodeHash($case['id']))->exists());
        $items[0]->salesOrderItem->product->update(['is_active' => true]);
        $draft = app(\App\Modules\CRM\Services\SalesOrderService::class)->createNoChargeReplacement(ReturnCase::findOrFail(ReturnCase::tryDecodeHash($case['id'])), $checker->id);
        $this->assertSame(SalesOrderStatus::Draft, $draft->status);
        $this->postJson($path.'/actions', ['action' => 'create_replacement'])->assertOk()
            ->assertJsonPath('data.replacement_order.id', $draft->hash_id)->assertJsonPath('data.replacement_order.status', 'confirmed');
        $this->assertSame(1, SalesOrder::query()->where('return_case_id', ReturnCase::tryDecodeHash($case['id']))->count());
    }

    public function test_report_holds_draft_invoice_posting_without_changing_original_quantities(): void
    {
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer, 1);
        $finance = User::factory()->create(['role_id' => Role::query()->where('slug', 'finance_officer')->value('id')]);
        $invoiceService = app(\App\Modules\Accounting\Services\InvoiceService::class);
        $this->actingAs($finance);
        $invoice = $invoiceService->create([
            'customer_id' => $customer->hash_id, 'sales_order_id' => $delivery->sales_order_id,
            'delivery_id' => $delivery->hash_id, 'date' => now()->toDateString(), 'is_vatable' => false,
            'items' => [['revenue_account_id' => \App\Modules\Accounting\Models\Account::query()->where('code', '4010')->firstOrFail()->hash_id,
                'description' => 'Original delivery', 'quantity' => '10', 'unit_price' => '25']],
        ], $finance);
        $this->actingAs($this->portal($customer), 'customer_portal')->postJson('/api/v1/b2b/customer/problems', $this->payload($delivery, $items, (string) Str::uuid()))->assertCreated();
        $this->assertSame('10.000', $items->first()->fresh()->quantity);
        $this->actingAs($finance);
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('awaiting resolution');
        $invoiceService->finalize($invoice, $finance);
    }
    public function test_customer_can_attach_and_download_evidence_but_cannot_see_internal_notes(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer, 1);
        $portal = $this->portal($customer);
        $case = $this->actingAs($portal, 'customer_portal')->postJson('/api/v1/b2b/customer/problems', $this->payload($delivery, $items, (string) Str::uuid()))->assertCreated()->json('data.id');
        $attachment = $this->postJson('/api/v1/b2b/customer/problems/'.$case.'/attachments', [
            'file' => UploadedFile::fake()->create('evidence.pdf', 10, 'application/pdf'),
        ])->assertCreated()->json('data.id');
        $this->get('/api/v1/b2b/customer/problems/'.$case.'/attachments/'.$attachment)->assertOk();
        $manager = User::factory()->create(['role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id')]);
        $this->actingAs($manager, 'web')->postJson('/api/v1/return-management/cases/'.$case.'/actions', [
            'action' => 'reply', 'message' => 'Internal cost investigation only.', 'is_public' => false,
        ])->assertOk();
        $response = $this->actingAs($portal, 'customer_portal')->getJson('/api/v1/b2b/customer/problems/'.$case)->assertOk();
        $this->assertStringNotContainsString('Internal cost investigation only.', $response->getContent());
        $this->assertStringNotContainsString('return-case-evidence/', $response->getContent());
        $response->assertJsonCount(1, 'data.attachments');
    }

    public function test_cancelled_unhandled_return_does_not_strand_the_case_agreement(): void
    {
        [$delivery, $items] = $this->source(Customer::factory()->create(), 1);
        $manager = User::factory()->create(['role_id' => Role::where('slug', 'customer_service_officer')->value('id')]);
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $this->payload($delivery, $items, (string) Str::uuid()))->assertCreated()->json('data');
        $path = '/api/v1/return-management/cases/'.$case['id'].'/actions';
        $agreement = ['action' => 'agree', 'resolution' => 'credit', 'message' => 'Credit missing and damaged goods after review.'];
        $this->postJson($path, $agreement)->assertOk();
        $rma = $this->postJson($path, ['action' => 'create_return'])->assertOk()->json('data.return_request');
        $this->postJson($path, $agreement)->assertUnprocessable();
        $this->postJson('/api/v1/return-management/return-requests/'.$rma['id'].'/cancel', ['reason' => 'Return collection is not required after inspection of evidence.'])->assertOk();
        $this->postJson($path, $agreement)->assertOk()->assertJsonPath('data.return_request', null)->assertJsonPath('data.status', 'action_agreed');
        $caseRecord = ReturnCase::findOrFail(ReturnCase::tryDecodeHash($case['id']));
        $this->assertTrue($caseRecord->events()->where('action', 'release_cancelled_return')->where('message', 'like', '%'.$rma['rma_number'].'%')->exists());
    }

    public function test_staff_evidence_does_not_clear_the_customer_information_request(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $customer = Customer::factory()->create();
        [$delivery, $items] = $this->source($customer, 1);
        $portal = $this->portal($customer);
        $manager = User::factory()->create(['role_id' => Role::where('slug', 'customer_service_officer')->value('id')]);
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $this->payload($delivery, $items, (string) Str::uuid()))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/return-management/cases/'.$case.'/actions', ['action' => 'request_info', 'message' => 'Please send the carton photos.'])->assertOk();
        $this->postJson('/api/v1/return-management/cases/'.$case.'/attachments', ['file' => UploadedFile::fake()->create('warehouse.pdf', 10, 'application/pdf')])->assertCreated();
        $this->getJson('/api/v1/return-management/cases/'.$case)->assertOk()->assertJsonPath('data.status', 'information_needed');
        $this->actingAs($portal, 'customer_portal')->postJson('/api/v1/b2b/customer/problems/'.$case.'/attachments', ['file' => UploadedFile::fake()->create('cartons.pdf', 10, 'application/pdf')])->assertCreated();
        $this->getJson('/api/v1/b2b/customer/problems/'.$case)->assertOk()->assertJsonPath('data.status', 'under_review');
    }

    public function test_private_staff_note_does_not_consume_a_request_for_customer_information(): void
    {
        [$delivery, $items] = $this->source(Customer::factory()->create(), 1);
        $manager = User::factory()->create(['role_id' => Role::where('slug', 'customer_service_officer')->value('id')]);
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', $this->payload($delivery, $items, (string) Str::uuid()))->assertCreated()->json('data');
        $path = '/api/v1/return-management/cases/'.$case['id'].'/actions';
        $this->postJson($path, ['action' => 'request_info', 'message' => 'Please confirm the carton count.'])->assertOk();
        $this->postJson($path, ['action' => 'reply', 'message' => 'Internal investigation is continuing.', 'is_public' => false])
            ->assertOk()->assertJsonPath('data.status', 'information_needed');
        $this->postJson($path, ['action' => 'reply', 'message' => 'Customer confirmed the count by phone.', 'is_public' => true])
            ->assertOk()->assertJsonPath('data.status', 'under_review');
    }

}
