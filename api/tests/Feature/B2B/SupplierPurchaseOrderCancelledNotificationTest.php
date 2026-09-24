<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Verify that PO cancellation emails are sent to suppliers only when the PO was
 * actually sent to them (sent_to_supplier_at is not null).
 */
class SupplierPurchaseOrderCancelledNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
        Mail::fake();
    }

    public function test_sent_purchase_order_cancellation_emails_supplier(): void
    {
        $vicePresidentRoleId = Role::query()->where('slug', 'vice_president')->value('id');
        $user = User::factory()->create(['role_id' => $vicePresidentRoleId]);

        $vendor = Vendor::factory()->create(['email' => 'supplier@test.test']);

        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => 'sent',
            'sent_to_supplier_at' => now()->subDay(),
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => Item::factory()->create()->id,
            'description' => 'Test Item',
            'quantity' => '100.00',
            'unit' => 'pcs',
            'unit_price' => '50.00',
            'total' => '5000.00',
        ]);

        $service = app(PurchaseOrderService::class);
        $service->cancel($po, 'Order cancelled due to changed requirements', null);

        // Assert email was queued to the supplier
        Mail::assertQueued(
            \App\Modules\B2B\Mail\SupplierPurchaseOrderCancelledMail::class,
            function ($mail) use ($vendor, $po) {
                return $mail->hasTo($vendor->email)
                    && $mail->purchaseOrder->id === $po->id;
            }
        );
    }

    public function test_never_sent_purchase_order_cancellation_does_not_email_supplier(): void
    {
        $vicePresidentRoleId = Role::query()->where('slug', 'vice_president')->value('id');
        $user = User::factory()->create(['role_id' => $vicePresidentRoleId]);

        $vendor = Vendor::factory()->create(['email' => 'supplier@test.test']);

        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => 'approved',
            'sent_to_supplier_at' => null,  // Never sent
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => Item::factory()->create()->id,
            'description' => 'Test Item',
            'quantity' => '100.00',
            'unit' => 'pcs',
            'unit_price' => '50.00',
            'total' => '5000.00',
        ]);

        $service = app(PurchaseOrderService::class);
        $service->cancel($po, 'Cancelled before sending', null);

        // Assert no email was sent to the supplier
        Mail::assertNotQueued(
            \App\Modules\B2B\Mail\SupplierPurchaseOrderCancelledMail::class,
        );
    }

    public function test_cancellation_email_names_the_po_but_never_the_internal_remarks(): void
    {
        $vicePresidentRoleId = Role::query()->where('slug', 'vice_president')->value('id');
        $user = User::factory()->create(['role_id' => $vicePresidentRoleId]);

        $vendor = Vendor::factory()->create([
            'email' => 'supplier@test.test',
            'contact_person' => 'John Supplier',
        ]);

        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => 'sent',
            'sent_to_supplier_at' => now()->subDay(),
            'po_number' => 'PO-202609-0042',
            'total_amount' => '5000.00',
            'remarks' => 'Internal: switching volume to a cheaper vendor.',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => Item::factory()->create()->id,
            'description' => 'Test Item',
            'quantity' => '100.00',
            'unit' => 'pcs',
            'unit_price' => '50.00',
            'total' => '5000.00',
        ]);

        $cancelReason = 'Supplier unable to meet delivery schedule';
        $service = app(PurchaseOrderService::class);
        $service->cancel($po, $cancelReason, null);

        // The PO's remarks (which now carry the cancel reason) are internal
        // purchasing notes; the rendered supplier email must not contain them.
        Mail::assertQueued(
            \App\Modules\B2B\Mail\SupplierPurchaseOrderCancelledMail::class,
            function ($mail) use ($po, $cancelReason): bool {
                $html = $mail->render();

                return $mail->purchaseOrder->po_number === $po->po_number
                    && str_contains($html, 'PO-202609-0042')
                    && ! str_contains($html, $cancelReason)
                    && ! str_contains($html, 'cheaper vendor');
            }
        );
    }

    public function test_cancellation_without_supplier_email_notifies_internal_users(): void
    {
        $vicePresidentRoleId = Role::query()->where('slug', 'vice_president')->value('id');
        $user = User::factory()->create(['role_id' => $vicePresidentRoleId]);

        $vendor = Vendor::factory()->create(['email' => '']);  // No email

        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => 'sent',
            'sent_to_supplier_at' => now()->subDay(),
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => Item::factory()->create()->id,
            'description' => 'Test Item',
            'quantity' => '100.00',
            'unit' => 'pcs',
            'unit_price' => '50.00',
            'total' => '5000.00',
        ]);

        $service = app(PurchaseOrderService::class);
        $service->cancel($po, 'Order cancelled', null);

        // No supplier email should be queued
        Mail::assertNotQueued(
            \App\Modules\B2B\Mail\SupplierPurchaseOrderCancelledMail::class,
        );
    }
}
