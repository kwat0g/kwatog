<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Quality\Controllers\InspectionController;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Resources\InspectionResource;
use App\Modules\Quality\Services\DefectParetoService;
use App\Modules\Quality\Services\InspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectionAuditContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_pareto_counts_capture_appropriate_failures_once_and_drilldown_matches(): void
    {
        $product = Product::factory()->create();
        foreach (['checklist', 'measurement'] as $capture) {
            $inspection = Inspection::create([
                'inspection_number' => 'AUD-'.$capture, 'stage' => 'customer_return', 'status' => 'failed',
                'capture_method' => $capture, 'source_quantity' => '10', 'source_uom_code' => 'PCS', 'sampling_basis' => 'piece', 'product_id' => $product->id,
                'batch_quantity' => 10, 'sample_size' => 1, 'completed_at' => now(),
            ]);
            foreach ([['failed', null], ['failed', false], ['passed', false]] as $index => [$result, $pass]) {
                DB::table('inspection_measurements')->insert([
                    'inspection_id' => $inspection->id, 'sample_index' => $index + 1,
                    'parameter_name' => 'Check', 'parameter_type' => 'dimensional',
                    'item_result' => $result, 'item_problem_description' => $result === 'failed' ? 'Size out of limit' : null, 'is_pass' => $pass, 'is_critical' => false,
                ]);
            }
        }
        $service = app(DefectParetoService::class);
        $filters = ['from' => now()->toDateString(), 'to' => now()->toDateString(), 'stage' => 'customer_return'];
        $this->assertSame(4, $service->run($filters)['total_defects']);
        $this->assertCount(2, $service->inspectionsWithDefect('Check', $filters));
    }

    public function test_options_and_chain_describe_effective_policy_and_terminal_outcome(): void
    {
        config(['inspection.checklist_capture_enabled' => true]);
        $controller = app(InspectionController::class);
        $sampling = array_column($controller->options()->getData(true)['data']['sampling_methods'], null, 'stage');
        $this->assertSame('aql', $sampling['outgoing']['value']);
        $this->assertSame('sample', $sampling['in_process']['value']);
        $this->assertSame('Planned sample', $sampling['in_process']['label']);
        $this->assertSame('Inspect this lot or all pieces', $sampling['incoming']['label']);
        foreach (['failed', 'cancelled'] as $status) {
            $inspection = new Inspection(['stage' => 'in_process', 'status' => $status]);
            $chain = $controller->chain($inspection)->getData(true)['data'];
            $this->assertSame('skipped', $chain[2]['state']);
            $this->assertSame(ucfirst($status), $chain[3]['label']);
            $this->assertSame('done', $chain[3]['state']);
            $this->assertCount(4, $chain);
        }
    }

    public function test_detail_uses_exact_receipt_line_and_permission_aware_ncr_link(): void
    {
        $inspection = new Inspection(['stage' => 'incoming', 'status' => 'passed']);
        $grn = new GoodsReceiptNote(['grn_number' => 'GRN-EXACT', 'status' => 'accepted']);
        $grn->id = 11;
        $line = new GrnItem([
            'quantity_received' => '251.000', 'quantity_accepted' => '251.000',
            'quantity_received_in_uom' => '125.500000', 'received_uom_code' => 'KG',
            'received_uom_conversion_factor' => '2',
        ]);
        $line->id = 12;
        $line->setRelation('grn', $grn);
        $line->setRelation('item', null);
        $ncr = new NonConformanceReport(['ncr_number' => 'NCR-EXACT', 'status' => 'open', 'disposition' => 'scrap']);
        $ncr->id = 13;
        $inspection->setRelation('grnItem', $line);
        $inspection->setRelation('ncr', $ncr);
        $request = Request::create('/');
        $data = (new InspectionResource($inspection))->toArray($request);
        $this->assertSame('125.500000', $data['incoming_acceptance']['quantity_accepted']);
        $this->assertSame('KG', $data['incoming_acceptance']['unit']);
        $this->assertSame('accepted', $data['incoming_acceptance']['status']);
        $this->assertSame('GRN-EXACT', $data['incoming_acceptance']['grn_number']);
        $this->assertNull($data['ncr']['href']);
        $user = \Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasPermission')->with('quality.ncr.view')->andReturn(true);
        $request->setUserResolver(fn () => $user);
        $data = (new InspectionResource($inspection))->toArray($request);
        $this->assertSame('/quality/ncrs/'.$ncr->hash_id, $data['ncr']['href']);
    }

    public function test_queue_counts_ignore_selected_status_and_pagination_but_keep_search_and_stage(): void
    {
        $product = Product::factory()->create();
        foreach (['draft', 'in_progress', 'awaiting_review', 'failed', 'passed', 'cancelled'] as $index => $status) {
            Inspection::create([
                'inspection_number' => 'AUD-'.$index, 'stage' => 'in_process', 'status' => $status,
                'product_id' => $product->id, 'batch_quantity' => 10, 'sample_size' => 1,
            ]);
        }
        $service = app(InspectionService::class);
        $filters = ['search' => 'AUD-', 'stage' => 'in_process', 'status' => 'failed', 'per_page' => 1];
        $this->assertSame(['to_inspect' => 2, 'awaiting_review' => 1, 'failed' => 1, 'all' => 6], $service->statusCounts($filters));
        unset($filters['status']);
        $filters['work_queue'] = 'to_inspect';
        $this->assertSame(2, $service->list($filters)->total());
        $this->assertSame(0, $service->statusCounts(['search' => 'absent'])['all']);
        $chain = app(InspectionController::class)->chain(Inspection::where('status', 'passed')->first())->getData(true)['data'];
        $this->assertSame('skipped', $chain[2]['state']);
        $this->assertSame('Passed', $chain[3]['label']);
        $this->assertNotContains('cancelled', array_column($chain, 'key'));
    }
}
