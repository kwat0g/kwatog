<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Modules\Accounting\Events\InvoiceFinalized;
use App\Modules\Accounting\Mail\CustomerInvoiceFinalizedMail;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\B2B\Services\SupplierPortalDispatchGateway;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Listeners\EmailCustomerOnSalesOrderConfirmed;
use App\Modules\CRM\Mail\CustomerSalesOrderConfirmedMail;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Purchasing\Mail\SupplierPurchaseOrderMail;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\SupplierDispatchResult;
use App\Modules\SupplyChain\Events\DeliveryConfirmed;
use App\Modules\SupplyChain\Listeners\EmailCustomerOnDeliveryConfirmed;
use App\Modules\SupplyChain\Mail\CustomerDeliveryConfirmedMail;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\Auth\Models\User;
use App\Common\Mail\EmailIntegrationTestMail;
use App\Common\Services\EmailBrandingService;
use Database\Seeders\RolePermissionSeeder;
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
        Mail::fake();
    }

    public function test_customer_sales_order_email_contains_order_data_and_portal_link(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'customer@example.test',
            'contact_person' => 'A. Customer',
        ]);
        $salesOrder = SalesOrder::factory()->create(['customer_id' => $customer->id]);

        app(EmailCustomerOnSalesOrderConfirmed::class)
            ->handle(new SalesOrderConfirmed($salesOrder));

        Mail::assertQueued(CustomerSalesOrderConfirmedMail::class, function (CustomerSalesOrderConfirmedMail $mail) use ($salesOrder): bool {
            $rendered = $mail->render();
            $mail->assertSeeInText($salesOrder->so_number);
            $mail->assertSeeInText('/portal/customer/orders/'.$salesOrder->hash_id);

            return $mail->hasTo('customer@example.test')
                && $mail->salesOrder->is($salesOrder)
                && str_contains($rendered, $salesOrder->so_number)
                && str_contains($rendered, '/portal/customer/orders/'.$salesOrder->hash_id)
                && str_contains($rendered, '<v:roundrect')
                && str_contains($rendered, 'Open this link if the button does not work');
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

    public function test_customer_delivery_email_contains_delivery_and_receiver_data(): void
    {
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
            return $mail->hasTo('delivery@example.test')
                && $mail->delivery->is($delivery)
                && str_contains($mail->render(), 'Receiving Clerk')
                && str_contains($mail->render(), $delivery->delivery_number);
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

            return $mail->hasTo('billing@example.test')
                && $mail->invoice->is($invoice)
                && str_contains($html, 'INV-EMAIL-001')
                && str_contains($html, '<title>Invoice INV-EMAIL-001 is ready</title>')
                && str_contains($html, 'PHP 1,120.00')
                && $mail->assertSeeInText('Due date')
                && $mail->assertSeeInText('/portal/customer/invoices/'.$invoice->hash_id)
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
        $queued->assertSeeInText('PHP 1,120.00');
        $queued->assertSeeInText('/portal/customer/invoices/'.$invoice->hash_id);
        $this->assertStringNotContainsString("\r", $queued->envelope()->subject);
        $this->assertStringNotContainsString("\n", $queued->envelope()->subject);
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
        ]);

        $result = app(SupplierPortalDispatchGateway::class)
            ->publish($purchaseOrder, 'purchase-order:email-test');

        $this->assertInstanceOf(SupplierDispatchResult::class, $result);
        Mail::assertQueued(SupplierPurchaseOrderMail::class, function (SupplierPurchaseOrderMail $mail) use ($purchaseOrder): bool {
            return $mail->hasTo('supplier@example.test')
                && $mail->purchaseOrder->is($purchaseOrder)
                && str_contains($mail->render(), 'PO-EMAIL-001')
                && str_contains($mail->render(), '/portal/supplier/purchase-orders/'.$purchaseOrder->hash_id);
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
