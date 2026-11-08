<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Payroll\Enums\ContributionAgency;
use App\Modules\Payroll\Events\GovernmentContributionTableChanged;
use App\Modules\Payroll\Models\GovernmentContributionTable;
use App\Modules\Payroll\Services\GovernmentContributionTableImportService;
use App\Modules\Payroll\Services\GovernmentContributionTableService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernmentTableRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_change_is_stored_as_one_private_content_free_outbox_ping(): void
    {
        $bracket = GovernmentContributionTable::query()->create([
            'agency' => 'sss',
            'bracket_min' => '765432.10',
            'bracket_max' => '765499.99',
            'ee_amount' => '10.0000',
            'er_amount' => '10.0000',
            'effective_date' => '2098-01-01',
            'is_active' => true,
        ]);

        app(GovernmentContributionTableService::class)->deactivate($bracket);

        $this->assertSame(1, OutboxMessage::query()
            ->where('event_type', GovernmentContributionTableChanged::class)
            ->count());
        $message = OutboxMessage::query()
            ->where('event_type', GovernmentContributionTableChanged::class)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $message->payload);

        $this->assertInstanceOf(GovernmentContributionTableChanged::class, $event);
        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('private-entity.government_table.visible', $event->broadcastOn()[0]->name);
        $this->assertSame([
            'refresh' => true,
            'event_id' => $event->eventId,
            'entity_type' => 'government_table',
            'action' => 'status_changed',
            'version' => $event->version,
            'timestamp' => $event->timestamp,
        ], $event->broadcastWith());
    }

    public function test_csv_import_stages_a_single_refresh_for_the_whole_import(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gov-table-realtime-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, implode("\n", [
                'bracket_min,bracket_max,ee_amount,er_amount,effective_date',
                '9876543.21,9876599.99,10,10,2099-01-01',
            ]));

            $result = app(GovernmentContributionTableImportService::class)
                ->importFromPath(ContributionAgency::Sss, $path, deactivatePrior: false);
        } finally {
            @unlink($path);
        }

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, OutboxMessage::query()
            ->where('event_type', GovernmentContributionTableChanged::class)
            ->count());
    }
}
