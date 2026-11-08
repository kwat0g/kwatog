<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Quality\Enums\PpapElementType;
use App\Modules\Quality\Enums\PpapLevel;
use App\Modules\Quality\Enums\PpapStatus;
use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Models\PpapElement;
use App\Modules\Quality\Models\PpapSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CalibrationPpapRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_and_ppap_child_writes_stage_content_free_parent_refreshes(): void
    {
        DB::transaction(function (): void {
            $calibration = CalibrationRecord::create([
                'equipment_code' => 'CAL-RT-001',
                'name' => 'Realtime gauge',
                'frequency_days' => 365,
                'status' => 'active',
            ]);

            $ppap = PpapSubmission::create([
                'ppap_number' => 'PP-RT-001',
                'vendor_id' => \App\Modules\Accounting\Models\Vendor::factory()->create()->id,
                'item_id' => \App\Modules\Inventory\Models\Item::factory()->create()->id,
                'ppap_level' => PpapLevel::Level3->value,
                'submission_date' => now()->toDateString(),
                'status' => PpapStatus::Draft->value,
            ]);
            PpapElement::create([
                'ppap_submission_id' => $ppap->id,
                'element_type' => PpapElementType::PartSubmissionWarrant->value,
                'status' => 'pending',
            ]);
        });

        $messages = DB::table('event_outbox')
            ->whereIn('event_type', [
                \App\Modules\Quality\Events\QualityRecordChanged::class,
            ])
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $messages, 'A PPAP plus its auto-created element must coalesce into one parent refresh.');
        $entities = [];

        foreach ($messages as $message) {
            $payload = json_decode((string) $message->payload, true, flags: JSON_THROW_ON_ERROR);
            $entities[] = $payload['entityType'];
            $this->assertArrayHasKey('action', $payload);
            $this->assertArrayHasKey('version', $payload);
            $this->assertArrayHasKey('eventId', $payload);
            $this->assertStringNotContainsString('name', strtolower((string) $message->payload));
            $this->assertStringNotContainsString('notes', strtolower((string) $message->payload));
        }

        sort($entities);
        $this->assertSame(['calibration', 'ppap'], $entities);
    }
}
