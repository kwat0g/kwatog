<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Modules\Accounting\Events\InvoiceFinalized;
use App\Modules\Accounting\Mail\CustomerInvoiceFinalizedMail;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
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
            return $mail->hasTo('customer@example.test')
                && $mail->salesOrder->is($salesOrder)
                && str_contains($mail->render(), $salesOrder->so_number)
                && str_contains($mail->render(), '/portal/customer/orders/'.$salesOrder->hash_id);
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
            return $mail->hasTo('billing@example.test')
                && $mail->invoice->is($invoice)
                && str_contains($mail->render(), 'INV-EMAIL-001')
                && str_contains($mail->render(), '1,120.00');
        });
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

        $this->assertStringContainsString('Ogami Philippines', $rendered);
        $this->assertStringContainsString('Philippine Ogami Corporation', $rendered);
        $this->assertStringContainsString('FCIE Complex, Dasmariñas, Cavite, Philippines', $rendered);
        $this->assertStringContainsString('cid:ogami-logo@ogami', $rendered);
        $this->assertStringNotContainsString('alt="Ogami Philippines" width="52" height="52"', $rendered);
    }
}
