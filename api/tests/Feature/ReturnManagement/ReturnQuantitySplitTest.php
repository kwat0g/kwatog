<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\QuarantineService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnReceipt;
use App\Modules\ReturnManagement\Models\ReturnReceiptItem;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestSourceAllocation;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnQuantitySplitTest extends TestCase
{
    use RefreshDatabase;

    private function receivedLine(string $type = 'customer_return'): array
    {
        $user = User::factory()->create();
        $item = Item::factory()->create(['unit_of_measure' => 'PCS']);
        $rma = ReturnRequest::create(['rma_number' => 'RMA-SPLIT-'.uniqid(), 'type' => $type,
            'status' => ReturnRequestStatus::Received, 'created_by' => $user->id, 'received_at' => now()]);
        $line = $rma->items()->create(['item_id' => $item->id, 'quantity' => '100.000',
            'returned_quantity' => '100.000', 'receipt_recorded' => true, 'unit_price' => '0.01', 'total' => '1.01']);
        $receipt = ReturnReceipt::create(['return_request_id' => $rma->id, 'request_key' => (string) Str::uuid(),
            'payload_fingerprint' => hash('sha256', 'split-fixture'), 'final_receipt' => true, 'received_by' => $user->id, 'received_at' => now()]);
        $receipt->items()->create(['return_request_item_id' => $line->id, 'quantity' => '100.000']);

        return [$rma, $line, $user];
    }

    public function test_split_conserves_received_quantity_money_and_exact_source_reservation(): void
    {
        [$rma, $line, $user] = $this->receivedLine('supplier_return');
        ReturnRequestSourceAllocation::create(['return_request_item_id' => $line->id,
            'source_kind' => 'grn_item', 'source_id' => 123, 'quantity' => '100.000', 'unit_price' => '0.01']);
        $updated = app(ReturnRequestService::class)->splitItem($rma, (int) $line->id, '1', $user);
        $new = $updated->items->firstWhere('id', '!=', $line->id);
        $this->assertCount(2, $updated->items);
        $this->assertSame('99.000', $line->fresh()->returned_quantity);
        $this->assertSame('1.000', $new->returned_quantity);
        $this->assertSame((int) $line->id, (int) $new->split_parent_item_id);
        $this->assertSame('1.01', bcadd((string) $line->fresh()->total, (string) $new->total, 2));
        $this->assertSame('100.000', bcadd((string) ReturnReceiptItem::where('return_request_item_id', $line->id)->value('quantity'),
            (string) ReturnReceiptItem::where('return_request_item_id', $new->id)->value('quantity'), 3));
        $this->assertSame('99.000', $line->sourceAllocations()->first()->quantity);
        $this->assertSame('1.000', $new->sourceAllocations()->first()->quantity);
        $this->assertSame(ReturnRequestStatus::Received, $updated->status);
    }

    public function test_customer_split_releases_99_and_scraps_1_from_one_conserved_receipt(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        [$rma, $line, $user] = $this->receivedLine();
        $zone = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $targetZone = WarehouseZone::factory()->create(['warehouse_id' => $zone->warehouse_id, 'zone_type' => 'finished_goods']);
        $target = WarehouseLocation::factory()->create(['zone_id' => $targetZone->id]);
        $scrapZone = WarehouseZone::factory()->create(['warehouse_id' => $zone->warehouse_id, 'zone_type' => 'scrap']);
        WarehouseLocation::factory()->create(['zone_id' => $scrapZone->id]);
        $movement = app(StockMovementService::class)->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn, itemId: (int) $line->item_id,
            toLocationId: (int) $quarantine->id, quantity: '100', referenceType: 'return_request',
            referenceId: (int) $rma->id, createdBy: (int) $user->id,
        ));
        $line->update(['quarantine_location_id' => $quarantine->id, 'quarantine_movement_id' => $movement->id, 'quarantine_status' => 'held']);
        ReturnReceiptItem::where('return_request_item_id', $line->id)->update(['stock_movement_id' => $movement->id]);
        $split = app(ReturnRequestService::class)->splitItem($rma, (int) $line->id, '1', $user);
        $bad = $split->items->firstWhere('id', '!=', $line->id);
        $this->assertTrue($bad->hasConservedReceiptAllocation($movement));
        $this->assertSame((int) $movement->id, (int) $bad->quarantine_movement_id);
        $split->update(['status' => ReturnRequestStatus::Inspected, 'disposition_status' => 'disposed']);
        $line->update(['disposition' => 'restock']);
        $bad->update(['disposition' => 'rework']);
        $service = app(QuarantineService::class);
        $goodHold = $service->holdCustomerReturn($line->fresh(), $user);
        $badHold = $service->holdCustomerReturn($bad->fresh(), $user);
        $reviewer = User::factory()->create(['role_id' => Role::where('slug', 'system_admin')->value('id')]);
        $service->release($goodHold, 'use_as_is', $reviewer, (int) $target->id, '99 verified good pieces',
            ['inspected_quantity' => '99', 'evidence_reference' => 'SPLIT-GOOD-99', 'recovery_passed' => true]);
        $service->release($badHold, 'scrap', $reviewer, null, '1 piece beyond repair');
        $this->assertSame('99.000', (string) StockLevel::where('item_id', $line->item_id)->where('location_id', $target->id)->value('quantity'));
        $this->assertSame('0.000', (string) StockLevel::where('item_id', $line->item_id)->where('location_id', $quarantine->id)->value('quantity'));
        $this->assertSame('99.000', $line->fresh()->stock_movement_quantity);
        $this->assertSame('1.000', $bad->fresh()->stock_movement_quantity);
        $this->assertSame('scrapped', $bad->fresh()->quarantine_status);
    }

    public function test_repeated_split_cannot_create_another_quantity_owner(): void
    {
        [$rma, $line, $user] = $this->receivedLine();
        app(ReturnRequestService::class)->splitItem($rma, (int) $line->id, '1', $user);
        try {
            app(ReturnRequestService::class)->splitItem($rma, (int) $line->id, '1', $user);
            $this->fail('Repeated split was accepted.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('already been split', $error->getMessage());
        }
        $this->assertSame(2, $rma->items()->count());
        $this->assertSame('99.000', $line->fresh()->returned_quantity);
    }

    public function test_fractional_piece_split_leaves_original_quantity_untouched(): void
    {
        [$rma, $line, $user] = $this->receivedLine();
        try {
            app(ReturnRequestService::class)->splitItem($rma, (int) $line->id, '1.5', $user);
            $this->fail('Fractional piece split was accepted.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('whole quantities', $error->getMessage());
        }
        $this->assertSame('100.000', $line->fresh()->returned_quantity);
        $this->assertSame(1, $rma->items()->count());
    }

    public function test_split_rejects_disposed_line(): void
    {
        [$rma, $line, $user] = $this->receivedLine();
        $line->update(['disposition' => 'scrap']);
        $this->expectException(BusinessRuleException::class);
        app(ReturnRequestService::class)->splitItem($rma, (int) $line->id, '1', $user);
    }
}
