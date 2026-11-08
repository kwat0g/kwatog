<?php

declare(strict_types=1);

namespace Tests\Feature\Quality;

use App\Modules\Auth\Models\User;
use App\Modules\Quality\Models\NcrTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NcrTemplateRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_catalog_writes_stage_one_content_free_refresh_per_transaction(): void
    {
        $user = User::factory()->create();
        $templateHash = null;

        DB::transaction(function () use ($user, &$templateHash): void {
            $template = NcrTemplate::create([
                'name' => 'Incoming resin contamination',
                'source' => 'incoming_inspection',
                'severity' => 'major',
                'defect_description' => 'Color variation beyond approved limit.',
                'created_by' => $user->id,
                'is_active' => true,
            ]);
            $templateHash = $template->hash_id;
            $template->update(['name' => 'Updated incoming resin template']);
        });

        $messages = DB::table('event_outbox')
            ->where('event_type', \App\Modules\Quality\Events\QualityRecordChanged::class)
            ->get();

        $this->assertCount(1, $messages);
        $payload = json_decode((string) $messages->first()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('ncr_template', $payload['entityType']);
        $this->assertSame($templateHash, $payload['hashId']);
    }
}
