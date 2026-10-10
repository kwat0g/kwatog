<?php
namespace Tests\Feature\Inventory;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\{Item,StockLevel,StockMovement,WarehouseLocation,WarehouseZone,StockCountSession,StockCountItem,MaterialIssueSlip,MaterialIssueSlipItem};
use App\Modules\Inventory\Services\{StockMovementService,StockTransferService,StockCountService,PickingListService};
use App\Modules\Inventory\Support\StockMovementInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
/** Audit probes assert observed defects; passing means reproduced, not fixed. */
class WarehouseDeepAuditProbeTest extends TestCase {
 use RefreshDatabase;
 protected function setUp(): void {parent::setUp(); Event::fake([StockMovementCompleted::class]);}
 private function receipt(Item $item, WarehouseLocation $bin, string $qty, ?string $lot): StockMovement {
  return app(StockMovementService::class)->move(new StockMovementInput(type:StockMovementType::AdjustmentIn,itemId:$item->id,toLocationId:$bin->id,quantity:$qty,unitCost:'100.00',lotNumber:$lot));
 }
 public function test_split_known_lots_are_rejected_for_aggregate_material_issue(): void {
  $item=Item::factory()->create();$bin=WarehouseLocation::factory()->create();
  $this->receipt($item,$bin,'5.000','A');$this->receipt($item,$bin,'5.000','B');
  $this->expectException(\App\Modules\Inventory\Exceptions\InsufficientStockException::class);
  app(StockMovementService::class)->move(new StockMovementInput(type:StockMovementType::MaterialIssue,itemId:$item->id,fromLocationId:$bin->id,quantity:'8.000'));
 }
 public function test_transfer_omits_untracked_remainder_when_known_lot_exists(): void {
  $item=Item::factory()->create();$bin=WarehouseLocation::factory()->create();$to=WarehouseLocation::factory()->create(['zone_id'=>$bin->zone_id]);
  $this->receipt($item,$bin,'5.000','A');$this->receipt($item,$bin,'5.000',null);
  $this->expectExceptionMessage('Insufficient available lot stock for this transfer.');
  app(StockTransferService::class)->transfer($item->id,$bin->id,$to->id,'10.000',null,User::factory()->create());
 }
 public function test_count_records_B_but_adjusts_A_and_reports_quantity_as_value(): void {
  $item=Item::factory()->create();$bin=WarehouseLocation::factory()->create();$counter=User::factory()->create();$checker=User::factory()->create();$finisher=User::factory()->create();
  $this->receipt($item,$bin,'5.000','A');$this->receipt($item,$bin,'5.000','B');
  $session=StockCountSession::create(['session_number'=>'SC-AUDIT-001','title'=>'Audit','scope'=>'zone','zone_id'=>$bin->zone_id,'status'=>'in_progress','created_by'=>$counter->id,'frozen_at'=>now()]);
  $line=StockCountItem::create(['session_id'=>$session->id,'location_id'=>$bin->id,'item_id'=>$item->id,'system_quantity'=>'10.000','status'=>'pending']);
  app(StockCountService::class)->recordCount($line->id,['counted_quantity'=>'8.000','lot_number'=>'B'],$counter);
  app(StockCountService::class)->approveVariance($line->id,$checker);
  app(StockCountService::class)->completeSession($session->id,$finisher);
  $movement=StockMovement::where('reference_type','stock_count_session')->where('reference_id',$session->id)->sole();
  $this->assertSame('B',$line->fresh()->lot_number);
  $this->assertSame('A',$movement->lot_number);
  $this->assertSame('2.00',(string)$session->fresh()->variance_value);
  $this->assertSame('200.00',(string)$movement->total_cost);
 }
 public function test_picking_suggests_B_after_issue_was_posted_from_A(): void {
  $item=Item::factory()->create();$bin=WarehouseLocation::factory()->create();$user=User::factory()->create();
  $this->receipt($item,$bin,'5.000','A');$this->receipt($item,$bin,'5.000','B');
  $slip=MaterialIssueSlip::create(['slip_number'=>'MIS-AUDIT-001','issued_date'=>today(),'issued_by'=>$user->id,'created_by'=>$user->id,'status'=>'issued','total_value'=>'500.00']);
  $movement=app(StockMovementService::class)->move(new StockMovementInput(type:StockMovementType::MaterialIssue,itemId:$item->id,fromLocationId:$bin->id,quantity:'5.000',lotNumber:'A',referenceType:'material_issue_slip',referenceId:$slip->id));
  MaterialIssueSlipItem::create(['material_issue_slip_id'=>$slip->id,'item_id'=>$item->id,'location_id'=>$bin->id,'quantity_issued'=>'5.000','unit_cost'=>'100.00','total_cost'=>'500.00','stock_movement_id'=>$movement->id,'lot_number'=>'A']);
  $picking=app(PickingListService::class)->generateForMis($slip->id);
  $this->assertSame('A',$movement->lot_number);
  $this->assertSame('B',$picking['lines'][0]['suggestions'][0]['lot_number']);
 }
 public function test_item_fallback_availability_counts_quarantine_as_available(): void {
  $item=Item::factory()->create();$zone=WarehouseZone::factory()->create(['zone_type'=>'quarantine']);$bin=WarehouseLocation::factory()->create(['zone_id'=>$zone->id]);
  StockLevel::create(['item_id'=>$item->id,'location_id'=>$bin->id,'quantity'=>'10.000','reserved_quantity'=>'0.000','weighted_avg_cost'=>'100.0000','lock_version'=>0]);
  $item->load('stockLevels');
  $this->assertSame('10.000',$item->available);
  $this->assertSame('0.000',app(\App\Modules\Inventory\Services\StockLocationSummaryService::class)->availability($item->stockLevels)['usable_quantity']);
 }
 public function test_expired_lot_can_still_be_issued(): void {
  $item=Item::factory()->create();$bin=WarehouseLocation::factory()->create();
  app(StockMovementService::class)->move(new StockMovementInput(type:StockMovementType::AdjustmentIn,itemId:$item->id,toLocationId:$bin->id,quantity:'2.000',unitCost:'100.00',lotNumber:'EXPIRED',expiryDate:today()->subDay()->toDateString()));
  $issued=app(StockMovementService::class)->move(new StockMovementInput(type:StockMovementType::MaterialIssue,itemId:$item->id,fromLocationId:$bin->id,quantity:'1.000'));
  $this->assertSame('EXPIRED',$issued->lot_number);
  $this->assertTrue($issued->expiry_date->isPast());
 }
 public function test_public_MRB_request_cannot_retain_requested_lot(): void {
  $rules=(new \App\Modules\Inventory\Requests\StoreMrbRequest)->rules();
  $this->assertArrayNotHasKey('lot_number',$rules);
  $this->assertArrayNotHasKey('expiry_date',$rules);
 }

 public function test_cancelling_handed_over_unlinked_issue_restock_without_return_receipt(): void {
  $item=Item::factory()->create();$bin=WarehouseLocation::factory()->create();$user=User::factory()->create();
  $this->receipt($item,$bin,'5.000','A');
  $slip=MaterialIssueSlip::create(['slip_number'=>'MIS-AUDIT-002','issued_date'=>today(),'issued_by'=>$user->id,'created_by'=>$user->id,'status'=>'issued','total_value'=>'500.00']);
  $movement=app(StockMovementService::class)->move(new StockMovementInput(type:StockMovementType::MaterialIssue,itemId:$item->id,fromLocationId:$bin->id,quantity:'5.000',lotNumber:'A',referenceType:'material_issue_slip',referenceId:$slip->id));
  MaterialIssueSlipItem::create(['material_issue_slip_id'=>$slip->id,'item_id'=>$item->id,'location_id'=>$bin->id,'quantity_issued'=>'5.000','unit_cost'=>'100.00','total_cost'=>'500.00','stock_movement_id'=>$movement->id,'lot_number'=>'A']);
  $employee=\App\Modules\HR\Models\Employee::factory()->create();
  \App\Modules\Inventory\Models\MaterialHandover::create(['stock_movement_id'=>$movement->id,'received_by_employee_id'=>$employee->id,'recipient_name'=>$employee->full_name,'recorded_by'=>$user->id,'quantity'=>'5.000','handed_over_at'=>now()]);
  app(\App\Modules\Inventory\Services\MaterialIssueService::class)->cancel($slip,$user);
  $this->assertSame('5.000',(string)StockLevel::where('item_id',$item->id)->where('location_id',$bin->id)->sole()->quantity);
  $this->assertSame('cancelled',$slip->fresh()->status->value);
 }

}
