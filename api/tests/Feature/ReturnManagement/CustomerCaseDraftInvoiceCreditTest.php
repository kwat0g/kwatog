<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A case credit has to offset a real bill.
 *
 * createCredit() looked the delivery's invoice up among finalized|partial|paid
 * only, so a delivery whose invoice was still a draft fell through to a credit
 * note with `invoice_id = null` while the draft kept billing the returned
 * goods: the customer was credited and still charged. The case now asks for the
 * invoice to be posted first, the same rule the supplier side applies to a
 * shortage that was never billed.
 */
class CustomerCaseDraftInvoiceCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    /**
     * A delivered line of 10 at ₱25.00 with its invoice, left in whatever
     * status the caller names.
     *
     * @return array{0: Delivery, 1: DeliveryItem, 2: Invoice}
     */
    private function deliveredWithInvoice(string $invoiceStatus): array
    {
        $customer = Customer::factory()->create();
        $creator = User::factory()->create();
        $order = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'status' => SalesOrderStatus::PartiallyDelivered->value,
            'created_by' => $creator->id,
        ]);
        $delivery = Delivery::create([
            'delivery_number' => 'DEL-DRAFT-'.Str::upper(Str::random(6)),
            'sales_order_id' => $order->id,
            'status' => 'delivered',
            'scheduled_date' => now()->toDateString(),
            'delivered_at' => now(),
            'created_by' => $creator->id,
        ]);
        $product = Product::create(['part_number' => 'DRAFT-'.Str::random(6), 'name' => 'Draft-credit part']);
        Item::factory()->create([
            'code' => $product->part_number,
            'name' => $product->name,
            'item_type' => ItemType::FinishedGood->value,
        ]);
        $orderLine = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => 10, 'quantity_delivered' => 10, 'unit_price' => 25,
        ]);
        $line = DeliveryItem::create([
            'delivery_id' => $delivery->id, 'sales_order_item_id' => $orderLine->id,
            'quantity' => 10, 'unit_price' => 25,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-DRAFT-'.substr(uniqid(), -6),
            'customer_id' => $customer->id,
            'sales_order_id' => $order->id,
            'delivery_id' => $delivery->id,
            'status' => $invoiceStatus,
            'subtotal' => '250.00', 'vat_amount' => '30.00', 'total_amount' => '280.00',
            'balance' => '280.00', 'date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'created_by' => $creator->id,
        ]);
        $invoice->items()->create([
            'revenue_account_id' => (int) Account::query()->where('code', '4010')->firstOrFail()->id,
            'product_id' => $product->id,
            'description' => 'Draft-credit part',
            'quantity' => '10.00', 'unit_price' => '25.00', 'total' => '250.00',
        ]);

        return [$delivery->fresh(), $line, $invoice];
    }

    /** File a one-piece shortage and agree to credit it. Returns the case hash id. */
    private function agreedCreditCase(Delivery $delivery, DeliveryItem $line): string
    {
        $manager = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id'),
        ]);
        $case = $this->actingAs($manager)->postJson('/api/v1/return-management/cases', [
            'source_kind' => 'delivery', 'source_id' => $delivery->hash_id,
            'description' => 'One piece missing from the delivery',
            'preferred_resolution' => 'credit',
            'request_key' => (string) Str::uuid(),
            'lines' => [[
                'source_line_id' => $line->hash_id, 'received_quantity' => '9.000',
                'defective_quantity' => '0.000', 'reason' => 'Short shipped',
            ]],
        ])->assertCreated()->json('data.id');

        $caseLine = $this->getJson('/api/v1/return-management/cases/'.$case)
            ->assertOk()->json('data.lines.0.id');

        $this->postJson('/api/v1/return-management/cases/'.$case.'/actions', [
            'action' => 'agree', 'resolution' => 'credit',
            'message' => 'Credit the verified shortage.',
            'lines' => [[
                'id' => $caseLine, 'verified_missing_quantity' => '1.000',
                'verified_defective_quantity' => '0.000',
            ]],
        ])->assertOk();

        return $case;
    }

    private function finance(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
    }

    public function test_a_case_cannot_credit_a_delivery_whose_invoice_is_still_a_draft(): void
    {
        [$delivery, $line, $invoice] = $this->deliveredWithInvoice('draft');
        $case = $this->agreedCreditCase($delivery, $line);

        $response = $this->actingAs($this->finance())
            ->postJson('/api/v1/return-management/cases/'.$case.'/actions', ['action' => 'create_credit'])
            ->assertUnprocessable();

        $this->assertStringContainsString(
            'Finalize invoice '.$invoice->invoice_number,
            $response->getContent(),
            'The operator is told which invoice to post before crediting.',
        );
        $this->assertDatabaseCount('credit_notes', 0);
        $this->assertNull(ReturnCase::query()->firstOrFail()->credit_note_id);
    }

    public function test_the_credit_links_to_the_invoice_once_it_is_finalized(): void
    {
        [$delivery, $line, $invoice] = $this->deliveredWithInvoice('finalized');
        $case = $this->agreedCreditCase($delivery, $line);

        $this->actingAs($this->finance())
            ->postJson('/api/v1/return-management/cases/'.$case.'/actions', ['action' => 'create_credit'])
            ->assertOk();

        $credit = CreditNote::query()->firstOrFail();
        $this->assertSame((int) $invoice->id, (int) $credit->invoice_id, 'The credit offsets the delivery invoice.');
        $this->assertSame('draft', $credit->status->value);
        $this->assertSame((int) ReturnCase::query()->firstOrFail()->credit_note_id, (int) $credit->id);
        // The case credits only the verified shortage: 1 × ₱25.00.
        $this->assertSame('25.00', (string) $credit->subtotal);
    }
}
