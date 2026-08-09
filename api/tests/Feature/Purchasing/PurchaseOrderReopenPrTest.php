<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
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
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-08-08 — When the last live PO sourced from a `converted` PR is
 * cancelled, rejected, or deleted, the PR flips back to `approved` so it can
 * be converted again. This matters most for auto-POs: an auto-created Draft PO
 * that gets cancelled would otherwise strand the PR in `converted` forever.
 */
class PurchaseOrderReopenPrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
    }

    private function makeConvertedPrWithPo(): array
    {
        $user = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create();

        $pr = PurchaseRequest::factory()->create([
            'requested_by'  => $user->id,
            'department_id' => null,
        ]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Converted->value])->save();
        PurchaseRequestItem::create([
            'purchase_request_id'  => $pr->id,
            'item_id'              => $item->id,
            'suggested_vendor_id'  => $vendor->id,
            'estimated_unit_price' => '250.00',
            'quantity'             => '10',
            'unit'                 => 'pcs',
            'description'          => 'Reopen test line',
        ]);

        $po = PurchaseOrder::create([
            'po_number'           => 'PO-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
            'vendor_id'           => $vendor->id,
            'purchase_request_id' => $pr->id,
            'date'                => now()->toDateString(),
            'subtotal'            => '2500.00',
            'vat_amount'          => '0.00',
            'total_amount'        => '2500.00',
            'is_vatable'          => false,
            'is_auto_generated'   => true,
            'created_by'          => $user->id,
        ]);
        $po->forceFill(['status' => PurchaseOrderStatus::Draft->value])->save();
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id'           => $item->id,
            'description'       => 'Reopen test line',
            'quantity'          => '10.00',
            'unit'              => 'pcs',
            'unit_price'        => '250.00',
            'total'             => '2500.00',
        ]);

        return [$pr, $po];
    }

    public function test_cancelling_the_last_po_reopens_the_pr_to_approved(): void
    {
        [$pr, $po] = $this->makeConvertedPrWithPo();

        app(PurchaseOrderService::class)->cancel($po, 'Wrong item spec.');

        $this->assertSame(PurchaseOrderStatus::Cancelled, $po->fresh()->status);
        $this->assertSame(PurchaseRequestStatus::Approved, $pr->fresh()->status);
    }

    public function test_deleting_the_last_draft_po_reopens_the_pr_to_approved(): void
    {
        [$pr, $po] = $this->makeConvertedPrWithPo();

        app(PurchaseOrderService::class)->delete($po);

        $this->assertSoftDeleted('purchase_orders', ['id' => $po->id]);
        $this->assertSame(PurchaseRequestStatus::Approved, $pr->fresh()->status);
    }

    public function test_rejecting_a_po_reopens_the_pr_to_approved(): void
    {
        [$pr, $po] = $this->makeConvertedPrWithPo();
        $svc = app(PurchaseOrderService::class);
        // The reject path acts on a pending approval step, so submit first.
        $svc->submit($po->fresh());

        $approver = User::factory()->create([
            'role_id' => \App\Modules\Auth\Models\Role::where('slug', 'purchasing_officer')->value('id'),
        ]);
        $svc->reject($po->fresh(), $approver, 'Missing PPAP.');

        $this->assertSame(PurchaseOrderStatus::Cancelled, $po->fresh()->status);
        $this->assertSame(PurchaseRequestStatus::Approved, $pr->fresh()->status);
    }

    public function test_pr_stays_converted_when_a_sibling_po_is_still_live(): void
    {
        [$pr, $po] = $this->makeConvertedPrWithPo();
        $vendor = Vendor::factory()->create();
        $sibling = PurchaseOrder::create([
            'po_number'           => 'PO-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
            'vendor_id'           => $vendor->id,
            'purchase_request_id' => $pr->id,
            'date'                => now()->toDateString(),
            'subtotal'            => '100.00',
            'vat_amount'          => '0.00',
            'total_amount'        => '100.00',
            'is_vatable'          => false,
            'created_by'          => $pr->requester?->id ?? User::factory()->create()->id,
        ]);
        $sibling->forceFill(['status' => PurchaseOrderStatus::Approved->value])->save();

        app(PurchaseOrderService::class)->cancel($po, 'One of two.');

        $this->assertSame(PurchaseRequestStatus::Converted, $pr->fresh()->status);
    }

    public function test_cancelling_a_po_whose_pr_was_not_converted_does_nothing(): void
    {
        [$pr, $po] = $this->makeConvertedPrWithPo();
        // Manually back-date the PR to approved — not converted.
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        app(PurchaseOrderService::class)->cancel($po, 'Cancelled while PR approved.');

        $this->assertSame(PurchaseRequestStatus::Approved, $pr->fresh()->status);
    }
}
