<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Mail\SupplierPoDecisionMail;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderResponse;
use App\Modules\Purchasing\Services\SupplierResponseService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupplierPoDecisionRecipientsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, WorkflowSeeder::class]);
    }

    public function test_each_active_supplier_recipient_gets_a_private_copy_and_duplicate_addresses_are_deduplicated(): void
    {
        $vendor = Vendor::factory()->create(['email' => 'fallback@example.test']);
        $purchaseOrder = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $response = $this->response($purchaseOrder, $vendor);
        SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'email' => 'buyer@example.test']);
        SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'email' => 'BUYER@example.test']);
        SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'email' => 'other@example.test']);
        SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'email' => 'inactive@example.test', 'is_active' => false]);
        Mail::fake();

        app(SupplierResponseService::class)->emailSupplierDecision($response, 'reject');

        Mail::assertQueued(SupplierPoDecisionMail::class, 2);
        Mail::assertQueued(SupplierPoDecisionMail::class, fn (SupplierPoDecisionMail $mail): bool => count($mail->to) === 1 && $mail->hasTo('buyer@example.test'));
        Mail::assertQueued(SupplierPoDecisionMail::class, fn (SupplierPoDecisionMail $mail): bool => count($mail->to) === 1 && $mail->hasTo('other@example.test'));
        Mail::assertNotQueued(SupplierPoDecisionMail::class, fn (SupplierPoDecisionMail $mail): bool => $mail->hasTo('inactive@example.test') || $mail->hasTo('fallback@example.test'));
    }

    public function test_invalid_portal_addresses_fall_back_to_a_valid_vendor_address(): void
    {
        $vendor = Vendor::factory()->create(['email' => 'vendor@example.test']);
        $purchaseOrder = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $response = $this->response($purchaseOrder, $vendor);
        SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'email' => 'invalid-address']);
        Mail::fake();

        app(SupplierResponseService::class)->emailSupplierDecision($response, 'accept');

        Mail::assertQueued(SupplierPoDecisionMail::class, 1);
        Mail::assertQueued(SupplierPoDecisionMail::class, fn (SupplierPoDecisionMail $mail): bool => count($mail->to) === 1 && $mail->hasTo('vendor@example.test'));
    }

    public function test_no_valid_external_address_notifies_only_active_purchasing_viewers(): void
    {
        $vendor = Vendor::factory()->create(['email' => 'invalid-vendor-address']);
        $purchaseOrder = PurchaseOrder::factory()->create(['vendor_id' => $vendor->id]);
        $response = $this->response($purchaseOrder, $vendor);
        SupplierPortalUser::factory()->create(['vendor_id' => $vendor->id, 'email' => 'invalid-address']);
        $buyer = User::factory()->create(['role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id')]);
        $inactiveBuyer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
            'is_active' => false,
        ]);
        $outsider = User::factory()->create(['role_id' => Role::query()->where('slug', 'employee')->value('id')]);
        Mail::fake();

        app(SupplierResponseService::class)->emailSupplierDecision($response, 'reject');

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('notifications', [
            'type' => 'email.delivery_failed',
            'notifiable_type' => User::class,
            'notifiable_id' => $buyer->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => 'email.delivery_failed',
            'notifiable_type' => User::class,
            'notifiable_id' => $inactiveBuyer->id,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'type' => 'email.delivery_failed',
            'notifiable_type' => User::class,
            'notifiable_id' => $outsider->id,
        ]);
    }

    private function response(PurchaseOrder $purchaseOrder, Vendor $vendor): PurchaseOrderResponse
    {
        return PurchaseOrderResponse::create([
            'purchase_order_id' => $purchaseOrder->id,
            'vendor_id' => $vendor->id,
            'response_type' => 'propose',
            'status' => 'rejected',
        ]);
    }
}
