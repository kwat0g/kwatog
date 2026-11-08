<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\HR\Models\Employee;
use App\Modules\Payroll\Events\DeMinimisBenefitChanged;
use App\Modules\Payroll\Models\DeMinimisBenefit;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeMinimisRealtimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_archive_and_restore_stage_private_refresh_signals(): void
    {
        $employee = Employee::factory()->create();
        $benefit = DeMinimisBenefit::query()->create([
            'employee_id' => $employee->id,
            'benefit_type' => 'rice_subsidy',
            'amount' => '1200.00',
            'period_year' => 2098,
            'period_month' => 1,
            'is_taxable_portion' => false,
        ]);

        $this->assertSignal('created', $benefit);

        DB::table('event_outbox')->delete();
        $benefit->delete();
        $this->assertSignal('deleted', $benefit);

        DB::table('event_outbox')->delete();
        DeMinimisBenefit::withTrashed()->findOrFail($benefit->id)->restore();
        $this->assertSignal('restored', $benefit);
    }

    private function assertSignal(string $action, DeMinimisBenefit $benefit): void
    {
        $message = OutboxMessage::query()
            ->where('event_type', DeMinimisBenefitChanged::class)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $message->payload);

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertSame('private-entity.payroll_de_minimis.visible', $event->broadcastOn()[0]->name);
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('payroll_de_minimis', $event->broadcastWith()['entity_type']);
        $this->assertSame($benefit->hash_id, $event->broadcastWith()['hash_id']);
        $this->assertSame($action, $event->broadcastWith()['action']);
        $this->assertSame([
            'refresh', 'event_id', 'entity_type', 'hash_id', 'action', 'version', 'timestamp',
        ], array_keys($event->broadcastWith()));
    }
}
