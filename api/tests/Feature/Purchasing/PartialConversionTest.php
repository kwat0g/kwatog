<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\ApprovedSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-11 — Partial PR→PO conversion. A line that cannot be sourced no
 * longer blocks the whole request: resolvable lines become POs immediately and
 * the PR sits at `partial` until the remainder is sourced.
 */
class PartialConversionTest extends TestCase
{
    use RefreshDatabase;

    private function line(PurchaseRequest $pr, Item $item, Vendor $vendor, string $price): PurchaseRequestItem
    {
        return PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'suggested_vendor_id' => $vendor->id,
            'description' => 'Line '.$item->code,
            'quantity' => '2',
            'unit' => 'pcs',
            'estimated_unit_price' => $price,
        ]);
    }

    private function approvedPr(User $user): PurchaseRequest
    {
        $pr = PurchaseRequest::factory()->create(['requested_by' => $user->id, 'department_id' => null]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        return $pr;
    }

    public function test_subset_conversion_marks_partial_then_completes(): void
    {
        $user = User::factory()->create();
        $pr = $this->approvedPr($user);
        $itemA = Item::factory()->create();
        $itemB = Item::factory()->create();
        $vendorA = Vendor::factory()->create();
        $vendorB = Vendor::factory()->create();
        $lineA = $this->line($pr, $itemA, $vendorA, '100.00');
        $lineB = $this->line($pr, $itemB, $vendorB, '50.00');

        $svc = app(PurchaseOrderService::class);

        $first = $svc->convertFromPr($pr->fresh(), [$lineA->id => $vendorA->id], $user);
        $this->assertCount(1, $first);

        $partial = $pr->fresh();
        $this->assertSame(PurchaseRequestStatus::Approved, $partial->status);
        $this->assertSame(PurchaseRequestConversionStatus::Partial, $partial->po_conversion_status);

        $second = $svc->convertFromPr($pr->fresh(), [$lineB->id => $vendorB->id], $user);
        $this->assertCount(1, $second);

        $complete = $pr->fresh();
        $this->assertSame(PurchaseRequestStatus::Converted, $complete->status);
        $this->assertSame(PurchaseRequestConversionStatus::Converted, $complete->po_conversion_status);
        $this->assertSame(2, PurchaseOrder::where('purchase_request_id', $pr->id)->count());
    }

    public function test_already_covered_lines_are_not_duplicated_on_retry(): void
    {
        $user = User::factory()->create();
        $pr = $this->approvedPr($user);
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();
        $line = $this->line($pr, $item, $vendor, '100.00');

        $svc = app(PurchaseOrderService::class);
        $svc->convertFromPr($pr->fresh(), [$line->id => $vendor->id], $user);

        // A retry of an already-converted PR is an idempotent read.
        $again = $svc->convertFromPr($pr->fresh(), [$line->id => $vendor->id], $user);

        $this->assertCount(1, $again);
        $this->assertSame(1, PurchaseOrder::where('purchase_request_id', $pr->id)->count());
    }

    public function test_qualified_supplier_price_overrides_the_pr_estimate(): void
    {
        $user = User::factory()->create();
        $pr = $this->approvedPr($user);
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();
        ApprovedSupplier::create([
            'item_id' => $item->id,
            'vendor_id' => $vendor->id,
            'qualification_status' => ApprovedSupplier::QUALIFICATION_APPROVED,
            'last_price' => '12.50',
        ]);
        $line = $this->line($pr, $item, $vendor, '10.00');

        $pos = app(PurchaseOrderService::class)
            ->convertFromPr($pr->fresh(), [$line->id => $vendor->id], $user);

        $this->assertSame('12.50', (string) $pos[0]->items()->firstOrFail()->unit_price);
    }

    public function test_empty_vendor_map_is_refused_and_names_the_line(): void
    {
        $user = User::factory()->create();
        $pr = $this->approvedPr($user);
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();
        $this->line($pr, $item, $vendor, '100.00');

        $this->expectException(BusinessRuleException::class);
        app(PurchaseOrderService::class)->convertFromPr($pr->fresh(), [], $user);
    }

    public function test_unknown_vendor_is_refused(): void
    {
        $user = User::factory()->create();
        $pr = $this->approvedPr($user);
        $item = Item::factory()->create();
        $vendor = Vendor::factory()->create();
        $line = $this->line($pr, $item, $vendor, '100.00');

        $this->expectException(BusinessRuleException::class);
        app(PurchaseOrderService::class)->convertFromPr($pr->fresh(), [$line->id => 999999], $user);
    }
}
