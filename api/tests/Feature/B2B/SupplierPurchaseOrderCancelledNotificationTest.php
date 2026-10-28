<?php

declare(strict_types=1);

namespace Tests\Feature\B2B;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Mail\SupplierPurchaseOrderCancelledMail;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Events\PurchaseOrderCancelled;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
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
                    && $mail->snapshot['po_hash_id'] === $po->hash_id;
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

                return $mail->snapshot['po_number'] === $po->po_number
                    && str_contains($html, 'PO-202609-0042')
                    && ! str_contains($html, $cancelReason)
                    && ! str_contains($html, 'cheaper vendor');
            }
        );
    }

    public function test_cancellation_notice_keeps_event_time_values_when_purchase_order_changes(): void
    {
        $vendor = Vendor::factory()->create([
            'email' => 'snapshot-supplier@example.test',
            'contact_person' => 'Original <img src=x onerror=alert(1)> Contact',
        ]);
        $po = PurchaseOrder::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => 'sent',
            'sent_to_supplier_at' => now()->subDay(),
            'po_number' => 'PO-SNAPSHOT-42',
            'date' => '2026-10-19',
            'total_amount' => '125.50',
        ]);

        $event = new PurchaseOrderCancelled($po->fresh());
        $codec = app(\App\Common\Services\OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);
        $mail = new SupplierPurchaseOrderCancelledMail($event->snapshot);

        $vendor->update(['email' => 'changed@example.test', 'contact_person' => 'Changed Contact']);
        $po->update(['po_number' => 'PO-CHANGED-99', 'total_amount' => '999.00', 'date' => '2026-10-20']);

        $html = $mail->render();
        self::assertSame('snapshot-supplier@example.test', $event->snapshot['recipient_email']);
        self::assertSame('PO-SNAPSHOT-42', $event->snapshot['po_number']);
        self::assertSame('Original <img src=x onerror=alert(1)> Contact', $event->snapshot['supplier_name']);
        self::assertSame($event->snapshot, $decoded->snapshot);
        self::assertSame('[Ogami Philippines] Purchase order cancelled: PO-SNAPSHOT-42', $mail->envelope()->subject);
        self::assertStringContainsString('PO-SNAPSHOT-42', $html);
        self::assertStringContainsString('<title>Purchase order cancelled</title>', $html);
        self::assertStringContainsString('Purchase order PO-SNAPSHOT-42 was cancelled.', $html);
        self::assertStringNotContainsString('PO-CHANGED-99', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString('Changed Contact', $html);
        self::assertStringContainsString($event->snapshot['display_total'], $html);
        self::assertStringContainsString(
            rtrim((string) config('app.frontend_url', config('app.url')), '/').'/portal/supplier/purchase-orders/',
            $html,
        );
        self::assertStringContainsString(
            e(app(\App\Common\Services\EmailBrandingService::class)->data()['address']),
            $html,
        );
        self::assertLessThan(102400, strlen($html));
        self::assertDoesNotMatchRegularExpression('/\{\{|undefined|>null</i', $html);
        $mail->assertSeeInText('Do not ship or invoice further items');
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

    public function test_queued_cancellation_email_failure_rechecks_po_row_visibility(): void
    {
        $role = Role::create([
            'name' => 'PO notice viewer '.substr(uniqid(), -5),
            'slug' => 'po_notice_viewer_'.substr(uniqid(), -5),
            'is_system' => false,
        ]);
        $role->permissions()->sync([
            Permission::query()->where('slug', 'purchasing.view')->value('id'),
        ]);
        $owner = User::factory()->create(['role_id' => $role->id]);
        $outsider = User::factory()->create(['role_id' => $role->id]);
        $purchaseOrder = PurchaseOrder::factory()->create(['created_by' => $owner->id]);
        $snapshot = [
            'po_hash_id' => $purchaseOrder->hash_id,
            'po_number' => $purchaseOrder->po_number,
        ];
        $mail = new SupplierPurchaseOrderCancelledMail($snapshot, [$owner->id, $outsider->id], $purchaseOrder);
        $mail = unserialize(serialize($mail));
        Log::shouldReceive('warning')->once()->with(
            'Notification recipients lack subject access; delivery suppressed.',
            [
                'type' => 'email.delivery_failed',
                'subject_type' => PurchaseOrder::class,
                'subject_id' => $purchaseOrder->id,
                'user_ids' => [$outsider->id],
            ],
        );

        $mail->failed(new RuntimeException('Mail delivery failed.'));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $owner->id,
            'type' => 'email.delivery_failed',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $outsider->id,
            'type' => 'email.delivery_failed',
        ]);
    }
}
