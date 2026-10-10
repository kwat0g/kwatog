<?php

declare(strict_types=1);

require_once '/var/www/tests/Feature/Quality/IncomingMrbDispositionTest.php';

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Inventory\Enums\MrbStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\QuarantineService;
use App\Modules\Inventory\Services\StockLocationSummaryService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;

/** Additional audit probes. Known failures belong outside the default regression suite. */
final class QcAdditionalIncomingProbeTest extends \Tests\Feature\Quality\IncomingMrbDispositionTest
{
    private function fixture(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod(parent::class, $method))->invoke($this, ...$arguments);
    }

    private function fixtureProperty(string $name): mixed
    {
        return (new \ReflectionProperty(parent::class, $name))->getValue($this);
    }

    public function test_extra_piece_quarantine_hold_rejects_fractional_quantity(): void
    {
        $grn = $this->fixture('receive', ['100.000'], 'PCS');
        $this->fixture('passLine', $grn, 0);
        $grn = $this->fixtureProperty('grns')->accept($grn->fresh(), $this->fixtureProperty('receiver'));
        $line = $grn->items->first();
        $before = StockMovement::where('item_id', $line->item_id)->count();
        try {
            app(QuarantineService::class)->hold(['item_id' => $line->item_id, 'quantity' => '0.500',
                'source_location_id' => $line->location_id], $this->fixtureProperty('mrb'));
            $this->fail('Half a physical piece was accepted into quarantine.');
        } catch (BusinessRuleException) {
            $this->assertSame($before, StockMovement::where('item_id', $line->item_id)->count());
        }
    }

    public function test_extra_partial_acceptance_rejects_fractional_physical_piece(): void
    {
        $grn = $this->fixture('receive', ['100.000'], 'PCS');
        $this->fixture('passLine', $grn, 0);
        $line = $grn->items->first();
        $before = StockMovement::where('item_id', $line->item_id)->count();
        try {
            $this->fixtureProperty('grns')->partialAccept($grn, [$line->id => '99.500'], $this->fixtureProperty('receiver'));
            $this->fail('Partial acceptance posted half a physical piece.');
        } catch (BusinessRuleException) {
            $this->assertSame('0.000', $line->fresh()->quantity_accepted);
            $this->assertSame($before, StockMovement::where('item_id', $line->item_id)->count());
        }
    }

    public function test_extra_late_ncr_cannot_hold_an_unrelated_lot_of_same_item(): void
    {
        $grn = $this->fixture('receive', ['100.000'], 'PCS');
        $grn->items->first()->update(['material_lot_number' => 'ORIGINAL-SUSPECT-LOT']);
        $this->fixture('passLine', $grn, 0);
        $grn = $this->fixtureProperty('grns')->accept($grn->fresh(), $this->fixtureProperty('receiver'));
        $line = $grn->items->first();
        $inspection = $this->fixture('inspectionFor', $grn, 0);
        app(StockMovementService::class)->move(new StockMovementInput(type: StockMovementType::Opening,
            itemId: $line->item_id, quantity: '10', toLocationId: $line->location_id,
            unitCost: '10', createdBy: $this->fixtureProperty('receiver')->id, lotNumber: 'UNRELATED-SAFE-LOT'));
        $ncr = $this->fixtureProperty('ncrs')->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 1,
            'defect_description' => 'Only original receipt lot suspect.'], $this->fixtureProperty('inspector'));
        $this->assertSame('100.000', $ncr->late_hold_quantity);
        try {
            app(QuarantineService::class)->hold(['item_id' => $line->item_id, 'quantity' => '1',
                'source_location_id' => $line->location_id, 'ncr_id' => $ncr->id,
                'inspection_id' => $inspection->id, 'lot_number' => 'UNRELATED-SAFE-LOT'], $this->fixtureProperty('mrb'));
            $this->fail('Unrelated lot satisfied original incident ownership.');
        } catch (BusinessRuleException) {
            $this->assertSame('10.000', app(StockLocationSummaryService::class)->lotQuantity($line->item_id, $line->location_id, 'UNRELATED-SAFE-LOT'));
        }
    }

    public function test_extra_untracked_late_hold_survives_transfer_to_another_bin(): void
    {
        $grn = $this->fixture('receive', ['100.000'], 'PCS');
        $this->fixture('passLine', $grn, 0);
        $grn = $this->fixtureProperty('grns')->accept($grn->fresh(), $this->fixtureProperty('receiver'));
        $line = $grn->items->first();
        $inspection = $this->fixture('inspectionFor', $grn, 0);
        WarehouseLocation::whereHas('zone', fn ($zone) => $zone->where('zone_type', 'quarantine'))
            ->update(['is_active' => false]);
        $ncr = $this->fixtureProperty('ncrs')->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 1,
            'defect_description' => 'Untracked receipt suspected before physical quarantine.'], $this->fixtureProperty('inspector'));
        $target = WarehouseLocation::factory()->create(['zone_id' => $this->fixtureProperty('location')->zone_id]);
        app(\App\Modules\Inventory\Services\StockTransferService::class)->transfer($line->item_id,
            $line->location_id, $target->id, '100', 'Move suspect material', $this->fixtureProperty('receiver'));
        $this->assertSame('100.000', app(\App\Modules\Quality\Services\QualityStockHoldService::class)->heldQuantity($line->item_id, $target->id),
            'Suspect stock became usable simply by changing bins.');
    }

    public function test_extra_late_incident_remains_selectable_after_missing_quarantine_setup(): void
    {
        $grn = $this->fixture('receive', ['100.000'], 'PCS');
        $this->fixture('passLine', $grn, 0);
        $grn = $this->fixtureProperty('grns')->accept($grn->fresh(), $this->fixtureProperty('receiver'));
        WarehouseLocation::whereHas('zone', fn ($zone) => $zone->where('zone_type', 'quarantine'))
            ->update(['is_active' => false]);
        $inspection = $this->fixture('inspectionFor', $grn, 0);
        $line = $grn->items->first();
        $ncr = $this->fixtureProperty('ncrs')->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 1,
            'defect_description' => 'Late defect requiring quarantine recovery.'], $this->fixtureProperty('inspector'));
        $this->assertFalse(MaterialReviewRecord::where('ncr_id', $ncr->id)->exists());
        $this->assertSame('100.000', app(\App\Modules\Quality\Services\QualityStockHoldService::class)->heldQuantity($line->item_id, $line->location_id));
        WarehouseLocation::whereHas('zone', fn ($zone) => $zone->where('zone_type', 'quarantine'))
            ->update(['is_active' => true]);
        $options = app(QuarantineService::class)->qualityOptions($line->item->hash_id);
        $this->assertContains($ncr->hash_id, array_column($options['ncrs'], 'id'), 'Late NCR must be selectable for recovery.');
        $this->assertContains($inspection->hash_id, array_column($options['inspections'], 'id'));
    }

    public function test_extra_late_reported_defect_cannot_release_entire_lot_without_repair(): void
    {
        $grn = $this->fixture('receive', ['100.000'], 'PCS');
        $this->fixture('passLine', $grn, 0);
        $grn = $this->fixtureProperty('grns')->accept($grn->fresh(), $this->fixtureProperty('receiver'));
        $inspection = $this->fixture('inspectionFor', $grn, 0);
        $ncr = $this->fixtureProperty('ncrs')->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 1,
            'defect_description' => 'One cracked piece confirmed after receipt acceptance.'], $this->fixtureProperty('inspector'));
        $hold = MaterialReviewRecord::where('ncr_id', $ncr->id)->firstOrFail();
        try {
            app(QuarantineService::class)->release($hold, 'use_as_is', $this->fixtureProperty('mrb'), $this->fixtureProperty('location')->id,
                'No repair performed.', ['inspected_quantity' => '100', 'evidence_reference' => 'LATE-ALL-100', 'recovery_passed' => true]);
            $this->fail('Known defect was released with the full lot without repair.');
        } catch (BusinessRuleException) {
            $this->assertSame(MrbStatus::Held, $hold->fresh()->status);
        }
    }

}
