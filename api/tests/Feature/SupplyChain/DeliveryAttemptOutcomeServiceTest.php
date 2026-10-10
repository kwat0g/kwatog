<?php

declare(strict_types=1);

namespace Tests\Feature\SupplyChain;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderItem;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\MovementGlPostingService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Models\WorkOrderOutput;
use App\Modules\Quality\Enums\InspectionParameterType;
use App\Modules\Quality\Enums\InspectionStatus;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionMeasurement;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\InspectionSpecItem;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use App\Modules\ReturnManagement\Services\ReturnCaseService;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryAttemptOutcome;
use App\Modules\SupplyChain\Services\DeliveryAttemptOutcomeService;
use App\Modules\SupplyChain\Services\DeliveryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\HasPassedInProcessQc;
use Tests\Support\PreparesDeliveryShipment;
use Tests\TestCase;

class DeliveryAttemptOutcomeServiceTest extends TestCase
{
    use HasPassedInProcessQc;
    use PreparesDeliveryShipment;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class, ChartOfAccountsSeeder::class]);
    }

    public function test_unaccounted_only_final_receipt_creates_no_stock_rma_or_gl_value(): void
    {
        [$delivery, $actor, $quarantine, $finishedGood, $soLine] = $this->dispatchedDelivery();
        $line = $delivery->items()->firstOrFail();
        $journalEntriesBefore = DB::table('journal_entries')->count();
        $service = app(DeliveryAttemptOutcomeService::class);

        $reported = $service->report($delivery, $actor, $this->reportPayload($line, '0', '0', '4'));
        $this->assertSame('0.000', (string) StockLevel::query()->where('item_id', $finishedGood->id)->where('location_id', $line->stockMovements()->firstOrFail()->from_location_id)->value('quantity'));
        $reconciled = $service->receive($reported, $actor, [
            'request_key' => (string) Str::uuid(),
            'quarantine_location_id' => null,
            'variance_reason' => 'The driver could not account for the four dispatched units.',
            'lines' => [['delivery_item_id' => $line->hash_id, 'received_quantity' => '0']],
        ]);

        $this->assertSame(DeliveryStatus::Returned, $reconciled->status);
        $this->assertSame('0.000', (string) $reconciled->attemptOutcome->items->first()->warehouse_received_quantity);
        $this->assertSame('4.000', (string) $reconciled->attemptOutcome->items->first()->unaccounted_quantity);
        $this->assertNull($reconciled->attemptOutcome->return_request_id);
        $this->assertSame(0, ReturnRequest::query()->count());
        $this->assertSame(0, StockMovement::query()->where('movement_type', StockMovementType::DeliveryReturn->value)->count());
        $this->assertSame(0, DB::table('credit_notes')->count());
        $this->assertSame($journalEntriesBefore, DB::table('journal_entries')->count());
        $this->assertSame('0.00', (string) $soLine->fresh()->quantity_delivered);
        $this->assertNull(StockLevel::query()->where('item_id', $finishedGood->id)->where('location_id', $quarantine->id)->value('quantity'));
    }

    public function test_depot_return_is_source_costed_non_gl_and_cannot_be_replayed_beyond_actual_receipt(): void
    {
        [$delivery, $actor, $quarantine, $finishedGood] = $this->dispatchedDelivery();
        $line = $delivery->items()->firstOrFail();
        $journalEntriesBefore = DB::table('journal_entries')->count();
        $attempts = app(DeliveryAttemptOutcomeService::class);
        $reported = $attempts->report($delivery, $actor, $this->reportPayload($line, '0', '4', '0'));
        $reconciled = $attempts->receive($reported, $actor, [
            'request_key' => (string) Str::uuid(),
            'quarantine_location_id' => $quarantine->hash_id,
            'variance_reason' => 'Only two of the four declared truck-return units arrived at the depot.',
            'lines' => [['delivery_item_id' => $line->hash_id, 'received_quantity' => '2']],
        ]);

        $this->assertSame(DeliveryStatus::Returned, $reconciled->status);
        $this->assertSame('2.000', (string) StockLevel::query()->where('item_id', $finishedGood->id)->where('location_id', $quarantine->id)->value('quantity'));
        $movement = StockMovement::query()->where('movement_type', StockMovementType::DeliveryReturn->value)->sole();
        $this->assertSame(StockMovementType::DeliveryReturn, $movement->movement_type);
        $this->assertSame('2.000', (string) $movement->quantity);
        $this->assertSame('4.5000', (string) $movement->unit_cost);
        $this->assertSame('9.00', (string) $movement->total_cost);
        $this->assertSame(MovementGlHandoffStatus::NotRequired, $movement->gl_handoff_status);
        $this->assertSame($journalEntriesBefore, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('credit_notes')->count());
        $this->assertSame(1, ReturnRequest::query()->count());

        $source = StockMovement::query()->where('movement_type', StockMovementType::Delivery->value)->sole();
        // A delivery return has to name the reconciled RMA line it settles, so
        // the replay must carry it to reach the cumulative-quantity guard.
        $returnItem = ReturnRequestItem::query()
            ->where('return_request_id', ReturnRequest::query()->sole()->id)->firstOrFail();
        try {
            app(StockMovementService::class)->move(new StockMovementInput(
                type: StockMovementType::DeliveryReturn,
                deliveryReturnItemId: (int) $returnItem->id,
                itemId: (int) $movement->item_id,
                toLocationId: $quarantine->id,
                quantity: '2.000',
                unitCost: (string) $source->unit_cost,
                referenceType: 'stock_movement',
                referenceId: (int) $source->id,
                lotNumber: $source->lot_number,
                expiryDate: $source->expiry_date?->toDateString(),
                totalCostOverride: '9.00',
                createdBy: $actor->id,
            ));
            $this->fail('A second movement must not recover more stock than the depot physically counted.');
        } catch (InvalidMovementException $error) {
            $this->assertStringContainsString('physically reconciled', $error->getMessage());
        }
        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovementType::DeliveryReturn->value)->count());
        $this->assertSame('2.000', (string) StockLevel::query()->where('item_id', $finishedGood->id)->where('location_id', $quarantine->id)->value('quantity'));
    }

    public function test_only_passed_checked_truck_restock_reopens_original_outgoing_capacity(): void
    {
        [$delivery, , $quarantine, $finishedGood, , $driver, , $product, $checker] = $this->dispatchedDelivery(withDriver: true);
        self::assertNotNull($driver);
        $line = $delivery->items()->firstOrFail();
        $this->actingAs($driver)
            ->postJson("/api/v1/driver/deliveries/{$delivery->hash_id}/attempt-outcome", $this->reportPayload($line, '0', '4', '0'))
            ->assertOk();
        $this->assertSame(DeliveryStatus::ReturnPending, $delivery->fresh()->status);

        $receiver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'is_active' => true,
        ]);
        $receipt = $this->actingAs($receiver)
            ->postJson("/api/v1/supply-chain/deliveries/{$delivery->hash_id}/truck-return-receipt", [
                'request_key' => (string) Str::uuid(),
                'quarantine_location_id' => $quarantine->hash_id,
                'variance_reason' => 'The depot counted two units from the four declared truck-return units.',
                'lines' => [['delivery_item_id' => $line->hash_id, 'received_quantity' => '2']],
            ]);
        $this->assertSame(200, $receipt->status(), $receipt->content());
        $received = $delivery->fresh();
        $this->assertSame(DeliveryStatus::Returned, $received->status);
        $this->assertSame([], app(DeliveryService::class)->inspectionOptions((int) $delivery->sales_order_id));
        $outcome = DeliveryAttemptOutcome::query()->where('delivery_id', $delivery->id)->sole();
        $rma = ReturnRequest::query()->with('items')
            ->where('delivery_attempt_outcome_id', $outcome->id)->sole();
        $rmaItem = $rma->items->sole();
        $this->assertSame('0.000', (string) $rmaItem->stock_movement_quantity, 'Depot receipt is not a final stock disposition.');
        $this->assertNotNull($rmaItem->quarantine_movement_id);

        $qualityMaker = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'qc_inspector')->value('id'),
            'is_active' => true,
        ]);
        $spec = InspectionSpec::query()->create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'created_by' => $qualityMaker->id,
        ]);
        InspectionSpecItem::query()->create([
            'inspection_spec_id' => $spec->id,
            'parameter_name' => 'Truck return condition',
            'parameter_type' => InspectionParameterType::Visual->value,
            'is_critical' => true,
            'sort_order' => 1,
        ]);
        $this->actingAs($qualityMaker)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/inspect")
            ->assertOk()
            ->assertJsonPath('data.status', 'inspected');
        $returnInspection = Inspection::query()
            ->where('entity_type', 'return_request')->where('entity_id', $rma->id)->sole();
        $this->assertTrue($returnInspection->requiresMakerChecker());
        $measurements = InspectionMeasurement::query()->where('inspection_id', $returnInspection->id)->get();
        $this->assertNotEmpty($measurements);
        $this->actingAs($qualityMaker)
            ->patchJson("/api/v1/quality/inspections/{$returnInspection->hash_id}/measurements", [
                'measurements' => $measurements->map(fn (InspectionMeasurement $measurement): array => [
                    'id' => $measurement->hash_id,
                    'is_pass' => true,
                ])->all(),
            ])
            ->assertOk();
        $this->actingAs($qualityMaker)
            ->postJson("/api/v1/quality/inspections/{$returnInspection->hash_id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', InspectionStatus::AwaitingReview->value);
        $this->assertSame(0, (int) $returnInspection->fresh()->accepted_quantity);
        $this->assertSame([], app(DeliveryService::class)->inspectionOptions((int) $delivery->sales_order_id));

        Role::query()->where('slug', 'qc_inspector')->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::query()->where('slug', 'quality.inspections.review')->value('id'),
        ]);
        $this->actingAs($qualityMaker)
            ->patchJson("/api/v1/quality/inspections/{$returnInspection->hash_id}/review", ['decision' => 'passed'])
            ->assertForbidden();
        $this->assertSame(InspectionStatus::AwaitingReview, $returnInspection->fresh()->status);
        $this->actingAs($checker)
            ->patchJson("/api/v1/quality/inspections/{$returnInspection->hash_id}/review", ['decision' => 'passed'])
            ->assertOk()
            ->assertJsonPath('data.status', InspectionStatus::Passed->value);
        $this->assertSame(0, (int) $returnInspection->fresh()->accepted_quantity, 'Only outgoing inspections release sellable production quantity.');

        $disposer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'customer_service_officer')->value('id'),
            'is_active' => true,
        ]);
        $this->actingAs($disposer)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [['item_id' => $rmaItem->hash_id, 'disposition' => 'restock']],
            ])
            ->assertOk();
        $rmaItem->refresh();
        $mrb = MaterialReviewRecord::query()->where('return_request_item_id', $rmaItem->id)->firstOrFail();
        $this->assertSame('held', $mrb->status->value);
        $this->assertSame($disposer->id, (int) $mrb->held_by);
        $this->assertSame('0.000', (string) $rmaItem->stock_movement_quantity);
        $this->assertSame('2.000', (string) StockLevel::query()
            ->where('item_id', $finishedGood->id)->where('location_id', $quarantine->id)->value('quantity'));
        $movementCount = StockMovement::query()->count();
        $this->actingAs($disposer)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete")
            ->assertUnprocessable();
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame([], app(DeliveryService::class)->inspectionOptions((int) $delivery->sales_order_id));

        $releaseActor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'is_active' => true,
        ]);
        $releaseZone = WarehouseZone::factory()->create([
            'warehouse_id' => $quarantine->zone->warehouse_id,
            'zone_type' => 'finished_goods',
        ]);
        $releaseLocation = WarehouseLocation::factory()->create(['zone_id' => $releaseZone->id]);
        $release = $this->actingAs($releaseActor)
            ->postJson("/api/v1/inventory/mrb/{$mrb->hash_id}/release", [
                'disposition' => 'use_as_is',
                'target_location_id' => $releaseLocation->hash_id,
                'inspected_quantity' => '2.000',
                'evidence_reference' => 'TRUCK-RETURN-REINSPECTION-'.$rma->rma_number,
                'recovery_passed' => true,
            ]);
        $this->assertSame(200, $release->status(), $release->content());
        $release->assertJsonPath('data.status', 'released');

        $mrb->refresh();
        $rmaItem->refresh();
        $this->assertSame($releaseActor->id, (int) $mrb->released_by);
        $this->assertSame($mrb->release_movement_id, $rmaItem->quarantine_release_movement_id);
        $this->assertSame('2.000', (string) $rmaItem->stock_movement_quantity);
        $this->assertSame('0.000', (string) StockLevel::query()
            ->where('item_id', $finishedGood->id)->where('location_id', $quarantine->id)->value('quantity'));
        $this->assertSame('2.000', (string) StockLevel::query()
            ->where('item_id', $finishedGood->id)->where('location_id', $releaseLocation->id)->value('quantity'));
        $mrbAudit = AuditLog::query()->where('model_type', $mrb->getMorphClass())
            ->where('model_id', $mrb->id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $mrbAudit->pluck('action')->all());
        $this->assertSame($releaseActor->id, (int) $mrbAudit->last()->user_id);

        $options = app(DeliveryService::class)->inspectionOptions((int) $delivery->sales_order_id);
        $this->assertCount(1, $options);
        $this->assertSame('2.00', $options[0]['remaining_quantity']);
        $movementCount = StockMovement::query()->count();
        $this->actingAs($disposer)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertSame($movementCount, StockMovement::query()->count(), 'RMA completion must not move released stock twice.');

        // Simulate corrupt legacy/import data only after the real chain completed.
        $releasedItem = $rmaItem->fresh();
        $releasedItem->forceFill(['disposition' => 'rework'])->save();
        $this->assertSame([], app(DeliveryService::class)->inspectionOptions((int) $delivery->sales_order_id), 'Rework stock does not replenish outgoing inspection capacity.');

        $releasedItem->forceFill(['disposition' => 'restock'])->save();
        $returnInspection->forceFill(['status' => 'failed'])->save();
        $this->assertSame([], app(DeliveryService::class)->inspectionOptions((int) $delivery->sales_order_id), 'A failed return inspection does not replenish capacity.');

        $returnInspection->forceFill(['status' => 'passed', 'reviewed_by' => $qualityMaker->id])->save();
        $this->assertSame([], app(DeliveryService::class)->inspectionOptions((int) $delivery->sales_order_id), 'The return inspector cannot approve their own result.');
    }

    public function test_report_and_depot_receipt_retries_are_idempotent_and_changed_payloads_conflict(): void
    {
        [$delivery, $actor, $quarantine] = $this->dispatchedDelivery();
        $line = $delivery->items()->firstOrFail();
        $service = app(DeliveryAttemptOutcomeService::class);

        $report = $this->reportPayload($line, '0', '4', '0');
        $report['request_key'] = (string) Str::uuid();
        $firstReport = $service->report($delivery, $actor, $report);
        $replayedReport = $service->report($delivery, $actor, $report);
        $this->assertSame($firstReport->attemptOutcome->id, $replayedReport->attemptOutcome->id);
        $changedReport = $report;
        $changedReport['notes'] = 'Changed after submit';
        try {
            $service->report($delivery, $actor, $changedReport);
            $this->fail('A report key cannot be replayed with changed outcome details.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('different delivery details', $error->getMessage());
        }

        $receipt = [
            'request_key' => (string) Str::uuid(),
            'quarantine_location_id' => $quarantine->hash_id,
            'lines' => [['delivery_item_id' => $line->hash_id, 'received_quantity' => '2']],
            'variance_reason' => 'The physical depot count differs from the driver declaration.',
        ];
        $firstReceipt = $service->receive($firstReport, $actor, $receipt);
        $replayedReceipt = $service->receive($firstReport, $actor, $receipt);
        $this->assertSame($firstReceipt->attemptOutcome->id, $replayedReceipt->attemptOutcome->id);
        $changedReceipt = $receipt;
        $changedReceipt['lines'][0]['received_quantity'] = '1';
        try {
            $service->receive($firstReport, $actor, $changedReceipt);
            $this->fail('A receipt key cannot be replayed with changed warehouse counts.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('different quantities or options', $error->getMessage());
        }
        $this->assertSame(1, ReturnRequest::query()->count());
        $this->assertSame(1, StockMovement::query()->where('movement_type', StockMovementType::DeliveryReturn->value)->count());
    }

    public function test_driver_received_damage_opens_a_customer_hold_only_for_accepted_units(): void
    {
        [$delivery, $actor, $quarantine] = $this->dispatchedDelivery();
        $line = $delivery->items()->firstOrFail();
        $attempts = app(DeliveryAttemptOutcomeService::class);
        $reported = $attempts->report($delivery, $actor, $this->reportPayload($line, '2', '2', '0', '1', '0'));
        $case = ReturnCase::query()->where('delivery_id', $delivery->id)->sole();
        $caseLine = $case->lines()->sole();
        $this->assertSame('2.000', (string) $caseLine->expected_quantity);
        $this->assertSame('1.000', (string) $caseLine->defective_quantity);
        $this->assertSame(1, ReturnCase::query()->where('delivery_id', $delivery->id)->count());

        $reconciled = $attempts->receive($reported, $actor, [
            'request_key' => (string) Str::uuid(),
            'quarantine_location_id' => $quarantine->hash_id,
            'lines' => [['delivery_item_id' => $line->hash_id, 'received_quantity' => '2']],
        ]);
        $this->assertSame(DeliveryStatus::Delivered, $reconciled->status);
        $this->assertSame('2.00', (string) $reconciled->salesOrder->items()->first()->quantity_delivered);

        try {
            app(DeliveryService::class)->confirm($reconciled, $actor);
            $this->fail('Customer-received damage must block confirmation and invoicing until reviewed.');
        } catch (BusinessRuleException $error) {
            $this->assertStringContainsString('problem report', strtolower($error->getMessage()));
        }
        $this->assertSame(0, DB::table('invoices')->count());
    }

    public function test_driver_report_for_another_assigned_route_is_hidden_as_not_found(): void
    {
        [$delivery, , , , , $assignedDriver] = $this->dispatchedDelivery(withDriver: true);
        $otherDriver = User::factory()->create(['role_id' => $assignedDriver->role_id]);
        $line = $delivery->items()->firstOrFail();

        try {
            app(DeliveryAttemptOutcomeService::class)->report(
                $delivery,
                $otherDriver,
                $this->reportPayload($line, '0', '4', '0'),
                driverSurface: true,
            );
            $this->fail("A driver must not report an exception on another driver's delivery.");
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }

    public function test_driver_correction_keeps_history_replays_and_rejects_stale_version(): void
    {
        [$delivery, $actor] = $this->dispatchedDelivery();
        $line = $delivery->items()->firstOrFail();
        $service = app(DeliveryAttemptOutcomeService::class);
        $service->report($delivery, $actor, $this->reportPayload($line, '0', '4', '0'));
        $payload = $this->reportPayload($line, '1', '2', '1') + ['expected_version' => 1, 'correction_reason' => 'Customer accepted one; another unit was missing.'];
        $changed = $service->amend($delivery, $actor, $payload);
        $this->assertSame(2, $changed->attemptOutcome->version);
        $this->assertSame('1.000', $changed->items->first()->customer_received_quantity);
        $revision = $changed->attemptOutcome->revisions()->firstOrFail();
        $this->assertSame('0.000', $revision->before_snapshot['lines'][0]['customer_received_quantity']);
        $this->assertSame('1.000', $revision->after_snapshot['lines'][0]['customer_received_quantity']);
        $service->amend($delivery, $actor, $payload);
        $this->assertSame(1, $changed->attemptOutcome->revisions()->count());
        $payload['request_key'] = (string) Str::uuid();
        $this->expectException(BusinessRuleException::class);
        $service->amend($delivery, $actor, $payload);
    }

    public function test_customer_damage_correction_updates_only_untouched_report(): void
    {
        [$delivery, $actor] = $this->dispatchedDelivery();
        $line = $delivery->items()->firstOrFail();
        $service = app(DeliveryAttemptOutcomeService::class);
        $service->report($delivery, $actor, $this->reportPayload($line, '2', '2', '0', '1'));
        $payload = $this->reportPayload($line, '2', '2', '0', '0') + ['expected_version' => 1, 'correction_reason' => 'Outer wrapping was marked; the accepted items are undamaged.'];
        $service->amend($delivery, $actor, $payload);
        $case = ReturnCase::query()->where('delivery_id', $delivery->id)->firstOrFail();
        $this->assertSame('withdrawn', $case->status->value);
        $this->assertTrue($case->events()->where('action', 'driver_report_corrected')->exists());
        $payload = $this->reportPayload($line, '2', '2', '0', '1') + ['expected_version' => 2, 'correction_reason' => 'Customer confirmed a damaged unit after unpacking.'];
        $service->amend($delivery, $actor, $payload);
        $case->refresh()->forceFill(['status' => 'under_review'])->save();
        $payload['request_key'] = (string) Str::uuid();
        $payload['expected_version'] = 3;
        $payload['lines'][0]['customer_received_damaged_quantity'] = '0';
        try {
            $service->amend($delivery, $actor, $payload);
            $this->fail('Reviewed damage claims must not be rewritten.');
        } catch (BusinessRuleException) {
            $this->assertSame(3, $delivery->fresh()->attemptOutcome->version);
            $this->assertSame('1.000', $case->fresh()->lines->first()->defective_quantity);
        }
    }

    public function test_late_recovery_after_zero_receipt_creates_separate_quarantined_batches_and_exact_cost(): void
    {
        [$delivery, $actor, $quarantine, $item] = $this->dispatchedDelivery();
        $line = $delivery->items()->firstOrFail();
        $service = app(DeliveryAttemptOutcomeService::class);
        $service->report($delivery, $actor, $this->reportPayload($line, '0', '0', '4'));
        $service->receive($delivery, $actor, ['request_key' => (string) Str::uuid(), 'variance_reason' => 'Not on the truck.',
            'lines' => [['delivery_item_id' => $line->hash_id, 'received_quantity' => '0']]]);
        $payload = ['request_key' => (string) Str::uuid(), 'variance_reason' => 'Found in the carrier holding bay.',
            'quarantine_location_id' => $quarantine->hash_id,
            'lines' => [['delivery_item_id' => $line->hash_id, 'received_quantity' => '1.333']]];
        $before = $delivery->fresh()->attemptOutcome;
        $returnMovements = StockMovement::query()->where('item_id', $item->id)
            ->where('movement_type', StockMovementType::DeliveryReturn)->count();
        try {
            $service->receiveLate($delivery, $actor, $payload);
            $this->fail('Piece recovery quantities must remain whole numbers.');
        } catch (BusinessRuleException $error) {
            $this->assertSame('Piece quantities must be whole numbers.', $error->getMessage());
        }
        $afterRejectedRecovery = $delivery->fresh()->attemptOutcome;
        $this->assertSame('0.000', $afterRejectedRecovery->items->first()->warehouse_received_quantity);
        $this->assertSame('4.000', $afterRejectedRecovery->items->first()->unaccounted_quantity);
        $this->assertSame($before->revisions()->count(), $afterRejectedRecovery->revisions()->count());
        $this->assertSame(0, ReturnRequest::query()->where('delivery_attempt_outcome_id', $before->id)->count());
        $this->assertSame($returnMovements, StockMovement::query()->where('item_id', $item->id)
            ->where('movement_type', StockMovementType::DeliveryReturn)->count());

        $payload['lines'][0]['received_quantity'] = '1';
        $first = $service->receiveLate($delivery, $actor, $payload);
        $service->receiveLate($delivery, $actor, $payload);
        $this->assertSame(1, ReturnRequest::query()->where('delivery_attempt_outcome_id', $first->attemptOutcome->id)->count());
        $payload['request_key'] = (string) Str::uuid();
        $payload['lines'][0]['received_quantity'] = '3';
        $second = $service->receiveLate($delivery, $actor, $payload);
        $this->assertSame(2, $second->attemptOutcome->returnRequests()->count());
        $this->assertSame('0.000', $second->attemptOutcome->items->first()->unaccounted_quantity);
        $this->assertSame('4.000', $second->attemptOutcome->items->first()->warehouse_received_quantity);
        $this->assertSame('18.00', bcadd((string) StockMovement::query()->where('item_id', $item->id)->where('movement_type', 'delivery_return')->sum('total_cost'), '0', 2));
        $this->assertSame(2, $second->attemptOutcome->revisions()->count());
        foreach ($second->attemptOutcome->returnRequests as $rma) {
            $rma->loadMissing('items');
            $this->assertSame('received', $rma->status->value);
            $this->assertSame('held', $rma->items->first()->quarantine_status);
            $this->assertNull($rma->credit_note_id);
        }
        $payload['request_key'] = (string) Str::uuid();
        $payload['lines'][0]['received_quantity'] = '0.001';
        $this->expectException(ValidationException::class);
        $service->receiveLate($delivery, $actor, $payload);
    }

    public function test_final_depot_count_cannot_be_rewritten_as_a_report_correction(): void
    {
        [$delivery, $actor, $quarantine] = $this->dispatchedDelivery();
        $line = $delivery->items()->firstOrFail();
        $service = app(DeliveryAttemptOutcomeService::class);
        $service->report($delivery, $actor, $this->reportPayload($line, '0', '4', '0'));
        $service->receive($delivery, $actor, ['request_key' => (string) Str::uuid(), 'quarantine_location_id' => $quarantine->hash_id,
            'lines' => [['delivery_item_id' => $line->hash_id, 'received_quantity' => '4']]]);
        $this->expectException(BusinessRuleException::class);
        $service->amend($delivery, $actor, $this->reportPayload($line, '1', '3', '0') + ['expected_version' => 1, 'correction_reason' => 'Try to change settled counts.']);
    }

    public function test_customer_can_track_non_arrival_without_claiming_credit_or_replacement(): void
    {
        [$delivery, $actor] = $this->dispatchedDelivery();
        $portal = CustomerPortalUser::create([
            'customer_id' => $delivery->salesOrder->customer_id, 'name' => 'Receiving desk',
            'email' => Str::uuid().'@example.test', 'password' => 'Testing!1234', 'is_active' => true,
        ]);
        $service = app(ReturnCaseService::class);
        $payload = ['request_key' => (string) Str::uuid(), 'message' => 'Our receiving dock is still waiting.'];
        $case = $service->reportNotArrived($delivery, $payload, $actor, $portal);
        $replay = $service->reportNotArrived($delivery, $payload, $actor, $portal);
        $this->assertSame($case->id, $replay->id);
        $this->assertSame('delivery_trace', $case->intake_kind->value);
        $this->assertCount(0, $case->lines);
        $this->assertNotNull($delivery->fresh()->blockingReturnCase);
        $by = ['type' => 'internal', 'id' => $actor->id, 'name' => $actor->name];
        try {
            $service->act($case, ['action' => 'agree', 'resolution' => 'credit', 'message' => 'Credit all units.'], $actor, $by);
            $this->fail('A tracking inquiry cannot authorize financial compensation.');
        } catch (BusinessRuleException) {
            $this->assertNull($case->fresh()->credit_note_id);
        }
        try {
            $service->act($case, ['action' => 'resolve_trace', 'message' => 'Still travelling.'], $actor, $by);
            $this->fail('Tracking cannot be closed before establishing the delivery outcome.');
        } catch (BusinessRuleException) {
            $this->assertSame('submitted', $case->fresh()->status->value);
        }
        $delivery->forceFill(['status' => DeliveryStatus::Delivered])->save();
        $resolved = $service->act($case, ['action' => 'resolve_trace', 'message' => 'The shipment has now arrived.'], $actor,
            ['type' => 'customer', 'id' => $portal->id, 'name' => $portal->name]);
        $this->assertSame('resolved', $resolved->status->value);
        $this->assertNull($delivery->fresh()->blockingReturnCase);
    }

    public function test_customer_tracking_intake_cannot_access_another_customers_delivery(): void
    {
        [$delivery, $actor] = $this->dispatchedDelivery();
        $portal = CustomerPortalUser::create([
            'customer_id' => Customer::factory()->create()->id, 'name' => 'Other customer',
            'email' => Str::uuid().'@example.test', 'password' => 'Testing!1234', 'is_active' => true,
        ]);
        try {
            app(ReturnCaseService::class)->reportNotArrived($delivery,
                ['request_key' => (string) Str::uuid()], $actor, $portal);
            $this->fail('Customer ownership must be checked in the locked service.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
            $this->assertSame(0, ReturnCase::query()->where('delivery_id', $delivery->id)->count());
        }
    }

    public function test_late_recovery_cannot_take_from_an_issue_allocation_already_received_by_customer(): void
    {
        [$delivery, $actor, $quarantine] = $this->dispatchedDelivery();
        $line = $delivery->items()->firstOrFail();
        $firstIssue = $line->stockMovements()->firstOrFail();
        $firstIssue->forceFill(['quantity' => '2.000', 'total_cost' => '9.00'])->save();
        $secondIssue = $firstIssue->replicate();
        $secondIssue->save();
        $service = app(DeliveryAttemptOutcomeService::class);
        $service->report($delivery, $actor, $this->reportPayload($line, '1', '1', '2'));
        $initial = $service->receive($delivery, $actor, ['request_key' => (string) Str::uuid(), 'quarantine_location_id' => $quarantine->hash_id,
            'variance_reason' => 'Two units still being traced.', 'lines' => [['delivery_item_id' => $line->hash_id, 'received_quantity' => '1']]]);
        $sources = $initial->attemptOutcome->items->first()->movements()->orderBy('id')->get();
        $this->assertSame('1.000', $sources[0]->customer_received_quantity);
        $this->assertSame('0.000', $sources[1]->customer_received_quantity);
        $service->receiveLate($delivery, $actor, ['request_key' => (string) Str::uuid(), 'quarantine_location_id' => $quarantine->hash_id,
            'variance_reason' => 'Located both missing units.', 'lines' => [['delivery_item_id' => $line->hash_id, 'received_quantity' => '2']]]);
        $sources = $initial->attemptOutcome->items->first()->movements()->orderBy('id')->get();
        $this->assertSame('1.000', $sources[0]->received_quantity);
        $this->assertSame('2.000', $sources[1]->received_quantity);
        $this->assertSame('1.000', $sources[0]->customer_received_quantity);
        $this->assertSame('2.000', bcadd((string) StockMovement::query()->where('movement_type', 'delivery_return')->where('reference_id', $secondIssue->id)->sum('quantity'), '0', 3));
    }

    /** @return array{Delivery, User, WarehouseLocation, Item, SalesOrderItem, User|null} */
    private function dispatchedDelivery(bool $withDriver = false): array
    {
        $actor = User::factory()->create();
        $checker = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'production_manager')->value('id'),
            'is_active' => true,
        ]);
        $assignedDriver = $withDriver
            ? User::factory()->create(['role_id' => Role::query()->where('slug', 'driver')->value('id')])
            : null;
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['unit_of_measure' => 'pcs', 'standard_cost' => '4.50']);
        $finishedGood = Item::factory()->create([
            'code' => $product->part_number,
            'item_type' => 'finished_good',
            'unit_of_measure' => 'pcs',
        ]);
        $finishedZone = WarehouseZone::factory()->create(['zone_type' => 'finished_goods']);
        $finishedLocation = WarehouseLocation::factory()->create(['zone_id' => $finishedZone->id]);
        $quarantineZone = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $quarantineZone->id]);
        StockLevel::factory()->create([
            'item_id' => $finishedGood->id,
            'location_id' => $finishedLocation->id,
            'quantity' => '4.000',
            'weighted_avg_cost' => '4.5000',
        ]);

        $order = SalesOrder::factory()->create(['customer_id' => $customer->id, 'created_by' => $actor->id]);
        $soLine = SalesOrderItem::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => '4.00',
            'quantity_delivered' => '0.00',
            'unit_price' => '15.00',
        ]);
        $workOrder = WorkOrder::create([
            'wo_number' => 'WO-EXC-'.Str::upper(Str::random(8)),
            'product_id' => $product->id,
            'sales_order_id' => $order->id,
            'sales_order_item_id' => $soLine->id,
            'quantity_target' => 4,
            'quantity_good' => 4,
            'quantity_produced' => 4,
            'planned_start' => now()->subDay(),
            'planned_end' => now(),
            'status' => 'completed',
            'created_by' => $actor->id,
        ]);
        $this->passInProcessQc($workOrder);
        $output = WorkOrderOutput::create([
            'work_order_id' => $workOrder->id,
            'recorded_by' => $actor->id,
            'recorded_at' => now(),
            'good_count' => 4,
            'reject_count' => 0,
            'batch_code' => 'LOT-'.Str::upper(Str::random(8)),
        ]);
        $receipt = StockMovement::create([
            'item_id' => $finishedGood->id,
            'to_location_id' => $finishedLocation->id,
            'movement_type' => StockMovementType::ProductionReceipt->value,
            'quantity' => '4.000',
            'unit_cost' => '4.5000',
            'total_cost' => '18.00',
            'reference_type' => 'work_order_output',
            'reference_id' => $output->id,
            'lot_number' => $output->batch_code,
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        app(MovementGlPostingService::class)->postFor($receipt);
        $this->assertSame(MovementGlHandoffStatus::Generated, $receipt->fresh()->gl_handoff_status);
        $output->update([
            'production_receipt_movement_id' => $receipt->id,
            'production_receipt_handoff_status' => 'generated',
        ]);
        $inspection = Inspection::create([
            'inspection_number' => 'QC-EXC-'.Str::upper(Str::random(8)),
            'stage' => 'outgoing',
            'status' => 'passed',
            'inspector_id' => $actor->id,
            'reviewed_by' => $checker->id,
            'reviewed_at' => now(),
            'product_id' => $product->id,
            'entity_type' => 'work_order',
            'entity_id' => $workOrder->id,
            'work_order_output_id' => $output->id,
            'batch_quantity' => 4,
            'accepted_quantity' => 4,
            'sample_size' => 1,
            'accept_count' => 1,
            'reject_count' => 0,
            'defect_count' => 0,
            'completed_at' => now(),
        ]);
        $delivery = app(DeliveryService::class)->create([
            'sales_order_id' => $order->id,
            'driver_id' => $assignedDriver?->id,
            'scheduled_date' => now()->toDateString(),
            'items' => [['sales_order_item_id' => $soLine->id, 'quantity' => '4.00', 'inspection_id' => $inspection->id]],
        ], $actor);
        $this->prepareShipmentForDispatch($delivery);
        $delivery->forceFill(['status' => DeliveryStatus::Loading->value, 'cost_recognition_mode' => 'legacy'])->save();
        $delivery = app(DeliveryService::class)->updateStatus($delivery, DeliveryStatus::InTransit);

        return [$delivery, $actor, $quarantine, $finishedGood, $soLine, $assignedDriver, $finishedLocation, $product, $checker];
    }

    private function reportPayload($line, string $accepted, string $truck, string $unaccounted, string $acceptedDamaged = '0', string $truckDamaged = '0'): array
    {
        return [
            'request_key' => (string) Str::uuid(),
            'reason_code' => 'customer_refused',
            'notes' => null,
            'lines' => [[
                'delivery_item_id' => $line->hash_id,
                'customer_received_quantity' => $accepted,
                'customer_received_damaged_quantity' => $acceptedDamaged,
                'truck_return_quantity' => $truck,
                'truck_return_damaged_quantity' => $truckDamaged,
                'unaccounted_quantity' => $unaccounted,
            ]],
        ];
    }
}
