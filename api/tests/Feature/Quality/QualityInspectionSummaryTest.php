<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Services\DefectParetoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityInspectionSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_uses_all_completed_inspections_in_the_requested_period(): void
    {
        $product = Product::factory()->create();
        foreach (['passed', 'passed', 'failed'] as $index => $status) {
            Inspection::create([
                'inspection_number' => 'SUM-'.($index + 1), 'stage' => 'outgoing', 'status' => $status,
                'product_id' => $product->id, 'batch_quantity' => 10, 'sample_size' => 10,
                'completed_at' => now()->subDays(5),
            ]);
        }
        Inspection::create([
            'inspection_number' => 'SUM-OLD', 'stage' => 'outgoing', 'status' => 'failed',
            'product_id' => $product->id, 'batch_quantity' => 10, 'sample_size' => 10,
            'completed_at' => now()->subDays(45),
        ]);

        $summary = app(DefectParetoService::class)->inspectionSummary([
            'from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString(),
        ]);

        $this->assertSame(3, $summary['total']);
        $this->assertSame(2, $summary['passed']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(66.67, $summary['pass_rate']);
    }
}
