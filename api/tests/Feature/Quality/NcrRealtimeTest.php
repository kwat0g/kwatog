<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Quality\Models\NcrAction;
use App\Modules\Quality\Models\NonConformanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NcrRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_ncr_and_action_changes_stage_one_refresh_for_the_parent_in_a_transaction(): void
    {
        $ncr = NonConformanceReport::factory()->create();
        $ncr->forceFill(['status' => 'in_progress'])->save();

        NcrAction::create([
            'ncr_id' => $ncr->id,
            'action_type' => 'containment',
            'description' => 'Quarantine the affected lot.',
            'performed_by' => $ncr->created_by,
            'performed_at' => now(),
        ]);

        $messages = DB::table('event_outbox')
            ->where('event_type', \App\Modules\Quality\Events\NcrChanged::class)
            ->get();

        $this->assertCount(
            1,
            $messages,
            'NCR root and action writes in one transaction should coalesce into one parent refresh.',
        );

        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($ncr->hash_id, $payload['ncrHashId']);
        $this->assertEqualsCanonicalizing(
            ['ncrHashId', 'action', 'version', 'actorId', 'eventId'],
            array_keys($payload),
        );
    }
}
