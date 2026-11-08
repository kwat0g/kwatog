<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\CRM\Models\Product;
use App\Modules\Quality\Events\InspectionChanged;
use App\Modules\Quality\Models\Inspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InspectionRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspection_changes_stage_one_content_free_refresh_per_transaction(): void
    {
        $inspection = Inspection::create([
            'inspection_number' => 'QC-LIVE-'.substr(uniqid(), -6),
            'stage' => 'outgoing',
            'status' => 'draft',
            'product_id' => Product::factory()->create()->id,
            'batch_quantity' => 10,
            'sample_size' => 5,
        ]);

        $inspection->forceFill(['status' => 'in_progress'])->save();
        $inspection->forceFill(['notes' => 'Evidence captured'])->save();

        $messages = DB::table('event_outbox')
            ->where('event_type', InspectionChanged::class)
            ->get();

        $this->assertCount(1, $messages, 'Several writes to one inspection in one transaction need one refresh ping.');
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($inspection->hash_id, $payload['inspectionHashId']);
        $this->assertEqualsCanonicalizing(
            ['inspectionHashId', 'action', 'version', 'actorId', 'eventId', 'actorHashId', 'timestamp'],
            array_keys($payload),
        );
        $this->assertSame('updated', $payload['action']);
        $this->assertSame($inspection->updated_at?->toISOString(), $payload['version']);
    }
}
