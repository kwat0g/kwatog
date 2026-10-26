<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Modules\Accounting\Events\InvoiceFinalized;
use App\Modules\Accounting\Events\CreditNoteFinalized;
use App\Modules\Accounting\Events\OfficialReceiptIssued;
use App\Modules\Accounting\Mail\CustomerInvoiceFinalizedMail;
use App\Modules\Accounting\Mail\CustomerOfficialReceiptMail;
use App\Modules\Accounting\Mail\PartyCreditNoteMail;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Accounting\Models\OfficialReceipt;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\B2B\Services\SupplierPortalDispatchGateway;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Listeners\EmailCustomerOnSalesOrderConfirmed;
use App\Modules\CRM\Mail\CustomerSalesOrderConfirmedMail;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\Item;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\CRM\Models\Product;
use App\Modules\Purchasing\Mail\SupplierPurchaseOrderMail;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Support\SupplierDispatchResult;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Listeners\EmailCustomerOnDeliveryConfirmed;
use App\Modules\SupplyChain\Mail\CustomerDeliveryConfirmedMail;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\Auth\Models\User;
use App\Common\Mail\EmailIntegrationTestMail;
use App\Common\Services\EmailBrandingService;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\SettingsService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class FeatureEmailIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        app(SettingsService::class)->set('modules.crm', true, 'modules');
        Mail::fake();
    }

    public function test_customer_sales_order_email_contains_order_data_and_portal_link(): void
    {
        config([
            'app.frontend_url' => 'https://portal.example.test',
            'app.url' => 'https://api.example.test',
        ]);
        $customer = Customer::factory()->create([
            'email' => 'customer@example.test',
            'contact_person' => 'A. <img src=x onerror=alert(1)> Customer',
        ]);
        $salesOrder = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'subtotal' => '120.00',
            'vat_amount' => '14.40',
            'total_amount' => '134.40',
        ]);
        $product = Product::factory()->create(['name' => 'Sample <img src=x onerror=alert(1)> molded component', 'part_number' => 'OG-TEST-01']);
        SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'quantity' => '2.00',
            'unit_price' => '60.00',
            'total' => '120.00',
        ]);

        app(EmailCustomerOnSalesOrderConfirmed::class)
            ->handle(new SalesOrderConfirmed($salesOrder));

        Mail::assertQueued(CustomerSalesOrderConfirmedMail::class, function (CustomerSalesOrderConfirmedMail $mail) use ($salesOrder): bool {
            $rendered = $mail->render();
            $mail->assertSeeInText($salesOrder->so_number);
            $mail->assertSeeInText('/portal/customer/orders/'.$salesOrder->hash_id);

            self::assertTrue($mail->hasTo('customer@example.test'));
            self::assertSame($salesOrder->so_number, $mail->snapshot['so_number']);
            self::assertSame('120.00', $mail->snapshot['subtotal']);
            self::assertSame('14.40', $mail->snapshot['vat_amount']);
            self::assertStringContainsString('<title>Sales order confirmed</title>', $rendered);
            self::assertStringContainsString('Subtotal', $rendered);
            self::assertStringContainsString(app(\App\Common\Services\CurrencyDisplayService::class)->format('14.40'), $rendered);
            self::assertStringContainsString('Order total', $rendered);
            self::assertStringContainsString(app(\App\Common\Services\CurrencyDisplayService::class)->format($salesOrder->total_amount), $rendered);
            self::assertStringContainsString('https://portal.example.test/portal/customer/orders/'.$salesOrder->hash_id, $rendered);
            self::assertStringNotContainsString('https://api.example.test/portal/customer/orders/', $rendered);
            self::assertStringContainsString('Sample molded component', $rendered);
            self::assertStringContainsString('Hello A. Customer,', $rendered);
            self::assertStringNotContainsString('<img src=x', $rendered);
            self::assertStringNotContainsString('onerror', $rendered);
            self::assertStringContainsString(app(\App\Common\Services\CurrencyDisplayService::class)->format('60.00'), $rendered);
            self::assertDoesNotMatchRegularExpression('/\{\{|undefined|>null</i', $rendered);
            self::assertLessThan(102400, strlen($rendered));
            self::assertStringContainsString('/portal/customer/orders/'.$salesOrder->hash_id, $rendered);
            self::assertStringContainsString('<v:roundrect', $rendered);
            self::assertStringContainsString('Open this link if the button does not work', $rendered);
            self::assertSame('['.app(\App\Common\Services\EmailBrandingService::class)->data()['name'].'] Sales order confirmed: '.$salesOrder->so_number.' · '.app(\App\Common\Services\CurrencyDisplayService::class)->format($salesOrder->total_amount), $mail->envelope()->subject);

            return true;
        });
    }

    public function test_customer_sales_order_email_uses_confirmation_time_values(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'customer@example.test',
            'contact_person' => 'Original Contact',
        ]);
        $salesOrder = SalesOrder::factory()->create([
            'customer_id' => $customer->id,
            'subtotal' => '120.00',
            'vat_amount' => '0.00',
            'total_amount' => '120.00',
        ]);
        $product = Product::factory()->create(['name' => 'Original Part', 'part_number' => 'PART-OLD']);
        $item = SalesOrderItem::factory()->create([
            'sales_order_id' => $salesOrder->id,
            'product_id' => $product->id,
            'quantity' => '2.00',
            'unit_price' => '60.00',
            'total' => '120.00',
        ]);
        $event = new SalesOrderConfirmed($salesOrder->fresh());

        $customer->update(['email' => 'changed@example.test', 'contact_person' => 'Changed Contact']);
        $salesOrder->update(['subtotal' => '999.00', 'vat_amount' => '0.00', 'total_amount' => '999.00']);
        $item->update(['quantity' => '9.00', 'unit_price' => '111.00', 'total' => '999.00']);
        $product->update(['name' => 'Changed Part', 'part_number' => 'PART-NEW']);

        app(EmailCustomerOnSalesOrderConfirmed::class)->handle($event);

        Mail::assertQueued(CustomerSalesOrderConfirmedMail::class, function (CustomerSalesOrderConfirmedMail $mail): bool {
            $rendered = $mail->render();

            return $mail->hasTo('customer@example.test')
                && str_contains($rendered, 'Original Contact')
                && str_contains($rendered, '120.00')
                && str_contains($rendered, 'Original Part')
                && str_contains($rendered, app(\App\Common\Services\CurrencyDisplayService::class)->format('120.00'))
                && ! str_contains($rendered, 'Changed Contact')
                && ! str_contains($rendered, '999.00')
                && ! str_contains($rendered, 'Changed Part');
        });
    }

    public function test_missing_customer_email_creates_a_sales_in_app_fallback(): void
    {
        $salesUser = User::factory()->withRole('system_admin')->create();
        $customer = Customer::factory()->create(['email' => null]);
        $salesOrder = SalesOrder::factory()->create(['customer_id' => $customer->id]);

        app(EmailCustomerOnSalesOrderConfirmed::class)
            ->handle(new SalesOrderConfirmed($salesOrder));

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $salesUser->id,
            'type' => 'email.delivery_failed',
        ]);
    }

    public function test_customer_delivery_email_contains_delivery_reference_and_portal_link(): void
    {
        $this->seed(SettingsSeeder::class);
        $creator = User::factory()->create();
        $customer = Customer::factory()->create(['email' => 'delivery@example.test']);
        $salesOrder = SalesOrder::factory()->create(['customer_id' => $customer->id]);
        $delivery = Delivery::create([
            'delivery_number' => 'DR-EMAIL-001',
            'sales_order_id' => $salesOrder->id,
            'status' => 'confirmed',
            'scheduled_date' => now()->toDateString(),
            'delivered_at' => now(),
            'confirmed_at' => now(),
            'created_by' => $creator->id,
            'receiver_name' => 'Receiving Clerk',
            'receiver_position' => 'Warehouse Supervisor',
        ]);

        app(EmailCustomerOnDeliveryConfirmed::class)
            ->handle(new DeliveryConfirmed($delivery, null));

        Mail::assertQueued(CustomerDeliveryConfirmedMail::class, function (CustomerDeliveryConfirmedMail $mail) use ($delivery): bool {
            $html = $mail->render();

            return $mail->hasTo('delivery@example.test')
                && $mail->delivery->is($delivery)
                && str_contains($html, $delivery->delivery_number)
                && str_contains($html, '/portal/customer/deliveries/'.$delivery->hash_id)
                && ! str_contains($html, 'Receiving Clerk')
                && ! str_contains($html, 'Warehouse Supervisor');
        });
    }

    public function test_missing_customer_delivery_email_fallback_carries_delivery_subject(): void
    {
        app(SettingsService::class)->set('modules.supply_chain', true, 'modules');
        $operator = User::factory()->withRole('system_admin')->create();
        $customer = Customer::factory()->create(['email' => null]);
        $salesOrder = SalesOrder::factory()->create(['customer_id' => $customer->id, 'created_by' => $operator->id]);
        $delivery = Delivery::create([
            'delivery_number' => 'DR-FALLBACK-001',
            'sales_order_id' => $salesOrder->id,
            'status' => 'confirmed',
            'scheduled_date' => today(),
            'created_by' => $operator->id,
        ]);

        app(EmailCustomerOnDeliveryConfirmed::class)->handle(new DeliveryConfirmed($delivery, null));

        Mail::assertNothingQueued();
        $notice = $operator->notifications()->where('type', 'email.delivery_failed')->firstOrFail();
        $this->assertSame(Delivery::class, $notice->data['subject_type']);
        $this->assertSame((string) $delivery->id, $notice->data['subject_id']);
        $this->assertSame('/supply-chain/deliveries/'.$delivery->hash_id, $notice->data['link_to']);
    }

    public function test_queued_customer_delivery_email_failure_fallback_carries_delivery_subject(): void
    {
        app(SettingsService::class)->set('modules.supply_chain', true, 'modules');
        $operator = User::factory()->withRole('system_admin')->create();
        $withoutView = User::factory()->create();
        $customer = Customer::factory()->create();
        $salesOrder = SalesOrder::factory()->create(['customer_id' => $customer->id, 'created_by' => $operator->id]);
        $delivery = Delivery::create([
            'delivery_number' => 'DR-QUEUE-FAIL-001',
            'sales_order_id' => $salesOrder->id,
            'status' => 'confirmed',
            'scheduled_date' => today(),
            'created_by' => $operator->id,
        ]);

        (new CustomerDeliveryConfirmedMail($delivery, null, [$operator->id, $withoutView->id]))
            ->failed(new \RuntimeException('Mail transport failed.'));

        $notice = $operator->notifications()->where('type', 'email.delivery_failed')->firstOrFail();
        $this->assertSame(Delivery::class, $notice->data['subject_type']);
        $this->assertSame((string) $delivery->id, $notice->data['subject_id']);
        $this->assertSame('/supply-chain/deliveries/'.$delivery->hash_id, $notice->data['link_to']);
        $this->assertSame(0, $withoutView->notifications()->where('type', 'email.delivery_failed')->count());
    }

    public function test_customer_delivery_email_uses_confirmation_time_values_after_outbox_replay(): void
    {
        $this->seed(SettingsSeeder::class);
        $creator = User::factory()->create();
        $customer = Customer::factory()->create([
            'email' => 'original-delivery@example.test',
            'contact_person' => 'Original Contact',
        ]);
        $salesOrder = SalesOrder::factory()->create(['customer_id' => $customer->id]);
        $salesOrderNumber = $salesOrder->so_number;
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-DELIVERY-OLD',
        ]);
        $delivery = Delivery::create([
            'delivery_number' => 'DR-SNAPSHOT-001',
            'sales_order_id' => $salesOrder->id,
            'status' => 'confirmed',
            'scheduled_date' => now()->toDateString(),
            'delivered_at' => '2026-04-03 08:15:00',
            'confirmed_at' => now(),
            'created_by' => $creator->id,
            'receiver_name' => 'Original Receiver',
            'receiver_position' => 'Store Manager',
            'delivery_remarks' => 'Original delivery note',
        ]);

        $event = new DeliveryConfirmed($delivery->fresh(), $invoice->id);
        $this->assertSame('original-delivery@example.test', $event->emailSnapshot['customer_email']);
        $encoded = app(OutboxEventCodec::class)->encode($event);
        $customer->update(['email' => 'changed-delivery@example.test', 'contact_person' => 'Changed Contact']);
        $salesOrder->update(['so_number' => 'SO-CHANGED']);
        $invoice->update(['invoice_number' => 'INV-DELIVERY-NEW']);
        $delivery->update([
            'delivery_number' => 'DR-CHANGED',
            'delivered_at' => '2026-04-04 09:30:00',
            'receiver_name' => 'Changed Receiver',
            'receiver_position' => 'Changed Position',
            'delivery_remarks' => 'Changed delivery note',
        ]);

        $replayed = app(OutboxEventCodec::class)->decode($encoded['event_type'], $encoded['payload']);
        $this->assertSame('original-delivery@example.test', $replayed->emailSnapshot['customer_email']);
        app(EmailCustomerOnDeliveryConfirmed::class)->handle($replayed);

        Mail::assertQueued(CustomerDeliveryConfirmedMail::class, function (CustomerDeliveryConfirmedMail $mail) use ($salesOrderNumber): bool {
            $html = $mail->render();
            $deliveryKey = (new \ReflectionProperty(\App\Common\Mail\QueuedMailable::class, 'deliveryKey'))->getValue($mail);

            return $mail->hasTo('original-delivery@example.test')
                && $mail->envelope()->subject === '[Ogami Philippines] Delivery confirmed: DR-SNAPSHOT-001'
                && str_contains($html, 'Original Contact')
                && str_contains($html, $salesOrderNumber)
                && ! str_contains($html, 'SO-CHANGED')
                && str_contains($html, 'April 3, 2026 8:15 AM (Asia/Manila)')
                && str_contains($html, 'INV-DELIVERY-OLD')
                && ! str_contains($html, 'Original Receiver')
                && ! str_contains($html, 'Original delivery note')
                && ! str_contains($html, 'Changed Contact')
                && ! str_contains($html, 'DR-CHANGED')
                && ! str_contains($html, 'INV-DELIVERY-NEW')
                && ! str_contains($html, 'Changed delivery note')
                && is_string($deliveryKey)
                && $deliveryKey === hash('sha256', 'customer-delivery-confirmed|'.$mail->snapshot['delivery_id'].'|original-delivery@example.test');
        });
    }

    public function test_finalized_invoice_email_contains_amounts_and_due_date(): void
    {
        $customer = Customer::factory()->create(['email' => 'billing@example.test']);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-EMAIL-001',
            'total_amount' => 1120,
            'balance' => 1120,
        ]);

        app(\App\Modules\Accounting\Listeners\EmailCustomerOnInvoiceFinalized::class)
            ->handle(new InvoiceFinalized($invoice));

        Mail::assertQueued(CustomerInvoiceFinalizedMail::class, function (CustomerInvoiceFinalizedMail $mail) use ($invoice): bool {
            $html = $mail->render();
            $amount = app(\App\Common\Services\CurrencyDisplayService::class)->format('1120.00');
            $mail->assertSeeInText('Due date');
            $mail->assertSeeInText('/portal/customer/invoices/'.$invoice->hash_id);

            return $mail->hasTo('billing@example.test')
                && $mail->invoice->is($invoice)
                && str_contains($html, 'INV-EMAIL-001')
                && str_contains($html, '<title>Invoice INV-EMAIL-001 is ready</title>')
                && str_contains($html, $amount)
                && str_contains($mail->envelope()->subject, 'Invoice issued: INV-EMAIL-001');
        });
    }

    public function test_invoice_finalized_event_keeps_issue_time_recipient_and_values(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'billing-at-issue@example.test',
            'name' => 'Customer at issue',
            'contact_person' => null,
        ]);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-SNAPSHOT-001',
            'total_amount' => '1120.00',
            'balance' => '1120.00',
        ]);
        $event = new InvoiceFinalized($invoice);
        $this->assertSame('billing-at-issue@example.test', $event->snapshot['customer_email']);

        $customer->update([
            'email' => 'billing-after@example.test',
            'name' => 'Changed customer',
        ]);
        $invoice->update(['total_amount' => '9999.00', 'balance' => '9999.00']);

        app(\App\Modules\Accounting\Listeners\EmailCustomerOnInvoiceFinalized::class)->handle($event);

        Mail::assertQueued(CustomerInvoiceFinalizedMail::class, function (CustomerInvoiceFinalizedMail $mail): bool {
            $html = $mail->render();
            $this->assertTrue($mail->hasTo('billing-at-issue@example.test'));
            $this->assertFalse($mail->hasTo('billing-after@example.test'));
            $this->assertStringContainsString('Customer at issue', $html);
            $this->assertStringNotContainsString('Changed customer', $html);
            $this->assertStringContainsString('1,120.00', $html);
            $this->assertStringNotContainsString('9,999.00', $html);

            return true;
        });
    }

    public function test_invoice_email_escapes_item_text_and_summarizes_long_lists(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'billing@example.test',
            'name' => 'Example Customer',
            'contact_person' => "Finance [team](https://evil.example)\r\n<script>alert(1)</script>",
        ]);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => "INV-1\r\nBcc:bad",
            'subtotal' => '1100.00',
            'vat_amount' => '20.00',
            'total_amount' => '1120.00',
            'balance' => '1120.00',
            'date' => '2026-10-20',
            'due_date' => '2026-11-19',
        ]);
        $account = Account::create([
            'code' => 'REV-EMAIL-TEST',
            'name' => 'Email test revenue',
            'type' => 'revenue',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        foreach (range(1, 11) as $index) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'revenue_account_id' => $account->id,
                'description' => $index === 1
                    ? '[invoice](https://evil.example) <script>alert(1)</script>'
                    : 'Molded component '.$index,
                'quantity' => '2.00',
                'unit' => 'pcs',
                'unit_price' => '50.00',
                'total' => '100.00',
            ]);
        }

        app(\App\Modules\Accounting\Listeners\EmailCustomerOnInvoiceFinalized::class)
            ->handle(new InvoiceFinalized($invoice));

        $queued = null;
        Mail::assertQueued(CustomerInvoiceFinalizedMail::class, function (CustomerInvoiceFinalizedMail $mail) use (&$queued): bool {
            $queued = $mail;

            return true;
        });

        $html = $queued->render();
        $this->assertStringContainsString('Finance', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringContainsString('Molded component 10', $html);
        $this->assertStringNotContainsString('Molded component 11', $html);
        $this->assertStringContainsString('1 additional item(s)', $html);
        $queued->assertSeeInText(app(\App\Common\Services\CurrencyDisplayService::class)->format('1120.00'));
        $queued->assertSeeInText('/portal/customer/invoices/'.$invoice->hash_id);
        $this->assertStringNotContainsString("\r", $queued->envelope()->subject);
        $this->assertStringNotContainsString("\n", $queued->envelope()->subject);
    }

    public function test_official_receipt_event_keeps_issue_time_recipient_and_values(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'receipt-at-issue@example.test',
            'name' => 'Customer at receipt issue',
            'contact_person' => null,
        ]);
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-RECEIPT-001',
        ]);
        $receipt = OfficialReceipt::create([
            'or_number' => 'OR-2026-001',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'amount' => '15000.00',
            'date' => '2026-10-20',
        ]);
        $event = new OfficialReceiptIssued($receipt);

        $customer->update([
            'email' => 'receipt-after@example.test',
            'name' => 'Changed customer',
        ]);
        $invoice->update(['invoice_number' => 'INV-CHANGED']);
        $receipt->update([
            'or_number' => 'OR-CHANGED',
            'amount' => '99999.00',
            'date' => '2026-12-31',
        ]);

        app(\App\Modules\Accounting\Listeners\EmailCustomerOnOfficialReceiptIssued::class)->handle($event);

        Mail::assertQueued(CustomerOfficialReceiptMail::class, function (CustomerOfficialReceiptMail $mail): bool {
            $html = $mail->render();
            $this->assertTrue($mail->hasTo('receipt-at-issue@example.test'));
            $this->assertFalse($mail->hasTo('receipt-after@example.test'));
            $this->assertStringContainsString('Customer at receipt issue', $html);
            $this->assertStringNotContainsString('Changed customer', $html);
            $this->assertStringContainsString('OR-2026-001', $html);
            $this->assertStringNotContainsString('OR-CHANGED', $html);
            $amount = app(\App\Common\Services\CurrencyDisplayService::class)->format('15000.00');
            $this->assertStringContainsString($amount, $html);
            $this->assertStringNotContainsString('99,999.00', $html);
            $this->assertStringContainsString('INV-RECEIPT-001', $html);
            $this->assertStringNotContainsString('INV-CHANGED', $html);
            $this->assertStringContainsString('<title>Official receipt OR-2026-001 issued</title>', $html);
            $this->assertStringContainsString('View related invoice', $html);
            $this->assertStringContainsString('quote the receipt number', $html);
            $this->assertStringContainsString('Official receipt issued: OR-2026-001 · '.$amount, $mail->envelope()->subject);
            $mail->assertSeeInText('/portal/customer/invoices/'.$mail->officialReceipt->invoice->hash_id);

            return true;
        });
    }

    public function test_customer_credit_note_event_keeps_issue_time_recipient_and_values(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'credit-at-issue@example.test',
            'name' => 'Customer at issue',
            'contact_person' => null,
        ]);
        $creditNote = CreditNote::create([
            'credit_note_number' => 'CN-2026-001',
            'type' => 'customer',
            'customer_id' => $customer->id,
            'date' => '2026-10-20',
            'subtotal' => '1000.00',
            'vat_amount' => '120.00',
            'total_amount' => '1120.00',
            'balance' => '1120.00',
            'reason' => 'Returned item',
        ]);
        $event = new CreditNoteFinalized($creditNote);

        $customer->update([
            'email' => 'credit-after@example.test',
            'name' => 'Changed customer',
        ]);
        $creditNote->update([
            'credit_note_number' => 'CN-CHANGED',
            'total_amount' => '9999.00',
            'reason' => 'Changed reason',
        ]);

        app(\App\Modules\Accounting\Listeners\EmailPartyOnCreditNoteFinalized::class)->handle($event);

        Mail::assertQueued(PartyCreditNoteMail::class, function (PartyCreditNoteMail $mail): bool {
            $html = $mail->render();

            $this->assertTrue($mail->hasTo('credit-at-issue@example.test'));
            $this->assertFalse($mail->hasTo('credit-after@example.test'));
            $this->assertStringContainsString('Customer at issue', $html);
            $this->assertStringNotContainsString('Changed customer', $html);
            $this->assertStringContainsString('CN-2026-001', $html);
            $this->assertStringNotContainsString('CN-CHANGED', $html);
            $amount = app(\App\Common\Services\CurrencyDisplayService::class)->format('1120.00');
            $this->assertStringContainsString($amount, $html);
            $this->assertStringNotContainsString('9,999.00', $html);
            $this->assertStringNotContainsString('Changed reason', $html);
            $this->assertStringContainsString('<title>Customer Credit note CN-2026-001 finalized</title>', $html);
            $this->assertStringContainsString('Open customer portal', $html);
            $this->assertStringContainsString('Customer credit note finalized: CN-2026-001 · '.$amount, $mail->envelope()->subject);
            $mail->assertSeeInText($amount);

            return true;
        });
    }

    public function test_supplier_credit_note_email_goes_to_vendor_address(): void
    {
        $vendor = Vendor::factory()->create(['email' => 'ap@example.test']);
        $creditNote = CreditNote::create([
            'credit_note_number' => 'SCN-2026-001',
            'type' => 'supplier',
            'vendor_id' => $vendor->id,
            'date' => '2026-10-20',
            'subtotal' => '1000.00',
            'vat_amount' => '120.00',
            'total_amount' => '1120.00',
            'balance' => '1120.00',
        ]);

        app(\App\Modules\Accounting\Listeners\EmailPartyOnCreditNoteFinalized::class)
            ->handle(new CreditNoteFinalized($creditNote));

        Mail::assertQueued(PartyCreditNoteMail::class, fn (PartyCreditNoteMail $mail): bool =>
            $mail->hasTo('ap@example.test')
            && ! $mail->hasTo('unknown@example.test')
            && str_contains($mail->render(), 'Supplier Credit note SCN-2026-001 finalized')
            && str_contains($mail->render(), 'Open supplier portal')
        );
    }

    public function test_supplier_purchase_order_email_is_queued_with_supplier_data(): void
    {
        $vendor = \App\Modules\Accounting\Models\Vendor::factory()->create([
            'email' => 'supplier@example.test',
            'contact_person' => 'Supplier Contact',
        ]);
        $purchaseOrder = PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
            'po_number' => 'PO-EMAIL-001',
            'total_amount' => '125.00',
        ]);
        $item = Item::factory()->create(['name' => 'Bracket [A]']);
        PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'item_id' => $item->id,
            'description' => 'Bracket [A]',
            'quantity' => '2.50',
            'unit' => 'pcs',
            'unit_price' => '50.00',
            'total' => '125.00',
        ]);

        $result = app(SupplierPortalDispatchGateway::class)
            ->publish($purchaseOrder, 'purchase-order:email-test');

        $this->assertInstanceOf(SupplierDispatchResult::class, $result);
        Mail::assertQueued(SupplierPurchaseOrderMail::class, function (SupplierPurchaseOrderMail $mail) use ($purchaseOrder): bool {
            $html = $mail->render();

            return $mail->hasTo('supplier@example.test')
                && $mail->purchaseOrder->is($purchaseOrder)
                && str_contains($html, 'PO-EMAIL-001')
                && str_contains($html, 'Bracket [A]')
                && str_contains($html, '₱125.00')
                && str_contains($html, '/portal/supplier/purchase-orders/'.$purchaseOrder->hash_id)
                && str_contains($html, 'background-color: #f7f4ef')
                && str_contains($html, 'border-bottom: 1px solid #f0eae0')
                && str_contains($html, 'border-color: #332c24 !important');
        });
    }

    public function test_email_shell_uses_ogami_philippines_identity_logo_and_company_details(): void
    {
        $mail = new EmailIntegrationTestMail();
        $rendered = $mail->render();
        $brand = app(EmailBrandingService::class)->data();

        $this->assertStringContainsString($brand['name'], $rendered);
        $this->assertStringContainsString($brand['legal_name'], $rendered);
        $this->assertStringContainsString(htmlspecialchars($brand['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $rendered);
        $this->assertStringContainsString('href="'.rtrim(config('app.frontend_url'), '/').'"', $rendered);
        $this->assertStringContainsString('cid:ogami-logo@ogami', $rendered);
        $this->assertStringContainsString('cid:ogami-logo-dark@ogami', $rendered);
        $this->assertStringContainsString('alt="'.$brand['name'].' logo"', $rendered);
        $this->assertStringContainsString('width="64" height="64"', $rendered);
        $this->assertStringContainsString('Shared email shell preview text.', $rendered);
        $this->assertStringContainsString('<title>AWS Mail Manager email integration test</title>', $rendered);
        $this->assertSame([0 => 275, 1 => 273], array_slice(getimagesize($brand['logo_path']), 0, 2, true));
        $this->assertSame([0 => 275, 1 => 273], array_slice(getimagesize($brand['logo_dark_path']), 0, 2, true));
        $darkLogo = imagecreatefrompng($brand['logo_dark_path']);
        $whitePixelFound = false;
        for ($y = 0; $y < imagesy($darkLogo); $y++) {
            for ($x = 0; $x < imagesx($darkLogo); $x++) {
                $pixel = imagecolorat($darkLogo, $x, $y);
                if ((($pixel >> 24) & 0x7f) === 0) {
                    $this->assertSame(0xffffff, $pixel & 0xffffff);
                    $whitePixelFound = true;
                    break 2;
                }
            }
        }
        imagedestroy($darkLogo);
        $this->assertTrue($whitePixelFound);
    }
}
