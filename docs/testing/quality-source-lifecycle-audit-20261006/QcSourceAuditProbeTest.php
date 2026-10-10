<?php

declare(strict_types=1);

require_once '/var/www/tests/Feature/Quality/IncomingMrbDispositionTest.php';

/** Disposable audit probes: assertions document observed gaps, not desired behavior. */
final class QcSourceAuditProbeTest extends \Tests\Feature\Quality\IncomingMrbDispositionTest
{
    private function fixture(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod(parent::class, $method))->invoke($this, ...$arguments);
    }

    private function fixtureProperty(string $name): mixed
    {
        return (new \ReflectionProperty(parent::class, $name))->getValue($this);
    }

    public function test_probe_fractional_good_pieces_are_accepted_for_pcs(): void
    {
        $grn = $this->fixture('receive', ['100.000'], 'pcs');
        $ncr = $this->fixture('failLine', $grn, 0);
        $this->fixtureProperty('ncrs')->setDisposition($ncr, 'return_to_supplier', 'Audit fractional pieces', null,
            $this->fixtureProperty('mrb'), '98.5', $this->fixture('sortingProof'));
        $this->assertSame('98.500', (string) $grn->fresh()->items->first()->quantity_accepted);
        $rma = \App\Modules\ReturnManagement\Models\ReturnRequest::query()->where('source_key', 'grn-rejection:'.$grn->id)->firstOrFail();
        $this->assertSame('1.500', (string) $rma->items->first()->quantity);
    }

    public function test_probe_incoming_scrap_creates_supplier_return(): void
    {
        $grn = $this->fixture('receive', ['100.000'], 'pcs');
        $ncr = $this->fixture('failLine', $grn, 0);
        $this->fixtureProperty('ncrs')->setDisposition($ncr, 'scrap', 'Audit scrap routing', null,
            $this->fixtureProperty('mrb'), '99', $this->fixture('sortingProof'));
        $rma = \App\Modules\ReturnManagement\Models\ReturnRequest::query()->where('source_key', 'grn-rejection:'.$grn->id)->firstOrFail();
        $this->assertSame('supplier_return', $rma->type->value);
        $this->assertSame('1.000', (string) $rma->items->first()->quantity);
    }

    public function test_probe_cancelled_incoming_inspection_survives_handoff_retry_without_replacement(): void
    {
        $grn = $this->fixture('receive', ['100.000'], 'pcs');
        $inspection = $this->fixture('inspectionFor', $grn, 0);
        app(\App\Modules\Quality\Services\InspectionService::class)->cancel($inspection, 'Audit cancellation', $this->fixtureProperty('inspector'));
        $retried = app(\App\Modules\Inventory\Services\GrnService::class)->retryIncomingQcHandoff($grn->fresh());
        $this->assertSame('generated', $retried->incoming_qc_handoff_status->value);
        $this->assertSame(0, \App\Modules\Quality\Models\Inspection::query()->where('grn_item_id', $inspection->grn_item_id)->where('status', '!=', 'cancelled')->count());
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        app(\App\Modules\Inventory\Services\GrnService::class)->accept($retried, $this->fixtureProperty('receiver'));
    }

    public function test_probe_closed_work_order_allows_new_checklist_but_blocks_submission(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $maker = $this->fixtureProperty('inspector');
        $product = \App\Modules\CRM\Models\Product::factory()->create();
        $spec = \App\Modules\Quality\Models\InspectionSpec::create(['product_id' => $product->id,
            'version' => 1, 'is_active' => true, 'created_by' => $maker->id]);
        \App\Modules\Quality\Models\InspectionSpecItem::create(['inspection_spec_id' => $spec->id,
            'parameter_name' => 'Surface', 'parameter_type' => 'visual', 'notes' => 'No cracks.']);
        $spec->ensureCurrentRevision();
        $wo = \App\Modules\Production\Models\WorkOrder::factory()->create(['product_id' => $product->id, 'status' => 'closed']);
        $output = \App\Modules\Production\Models\WorkOrderOutput::create(['work_order_id' => $wo->id,
            'recorded_by' => $maker->id, 'recorded_at' => now(), 'good_count' => 100, 'reject_count' => 0]);
        $service = app(\App\Modules\Quality\Services\InspectionService::class);
        $inspection = $service->create(['stage' => 'outgoing', 'product_id' => $product->id,
            'batch_quantity' => 100, 'work_order_output_id' => $output->id], $maker);
        $this->assertTrue($inspection->usesChecklistCapture());
        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->expectExceptionMessage('source output changed or was cancelled');
        $service->recordBatchResult($inspection, ['expected_result_version' => 0, 'coverage_confirmed' => true,
            'items' => $inspection->measurements->map(static fn ($row): array => ['id' => $row->id, 'result' => 'passed'])->all()], $maker);
    }

    public function test_probe_late_incoming_ncr_does_not_hold_already_accepted_material(): void
    {
        $grn = $this->fixture('receive', ['100.000'], 'pcs');
        $this->fixture('passLine', $grn, 0);
        $grn = app(\App\Modules\Inventory\Services\GrnService::class)->accept($grn->fresh(), $this->fixtureProperty('receiver'));
        $inspection = $this->fixture('inspectionFor', $grn, 0);
        $line = $grn->items->first();
        $location = $this->fixtureProperty('location');
        $levels = \App\Modules\Inventory\Models\StockLevel::query()->where('item_id', $line->item_id)->with('location.zone.warehouse')->get();
        $summary = app(\App\Modules\Inventory\Services\StockLocationSummaryService::class);
        $before = $summary->availability($levels);
        $ncr = $this->fixtureProperty('ncrs')->create(['source' => 'inspection_fail', 'severity' => 'high',
            'inspection_id' => $inspection->id, 'affected_quantity' => 1, 'defect_description' => 'Late defect on accepted raw material'], $this->fixtureProperty('inspector'));
        $this->assertNotNull($ncr->id);
        $after = $summary->availability($levels);
        $this->assertSame($before['usable_quantity'], $after['usable_quantity']);
        $this->assertSame('0.000', $after['quality_held_quantity']);
    }
}
