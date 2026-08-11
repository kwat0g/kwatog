<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\ChainStepAdvanced;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\OutboxService;
use App\Modules\CRM\Models\SalesOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Series C — Task C4. Unit tests for ChainBroadcaster.
 *
 * Uses Event::fake() — we don't actually want to talk to Reverb during
 * tests. We're verifying that the right payload is dispatched.
 */
class ChainBroadcasterTest extends TestCase
{
    public function test_broadcasts_chain_step_advanced_for_known_model(): void
    {
        Event::fake([ChainStepAdvanced::class]);

        $so = new SalesOrder();
        $so->id = 42;
        $so->so_number = 'SO-202604-0042';

        $b = app(ChainBroadcaster::class);
        $ok = $b->broadcastFor($so, 'confirmed');

        $this->assertTrue($ok);
        Event::assertDispatched(ChainStepAdvanced::class, function (ChainStepAdvanced $e) {
            return $e->entityType === 'sales_order'
                && $e->newStatus === 'confirmed'
                && $e->activeStep === 'confirmed'
                && $e->docNumber === 'SO-202604-0042';
        });
    }

    public function test_rejects_unsupported_model_instead_of_silently_losing_chain_evidence(): void
    {
        Event::fake([ChainStepAdvanced::class]);

        $other = new class extends Model {
            protected $table = 'fake';
            public $id = 1;
        };

        $b = app(ChainBroadcaster::class);

        $this->expectException(\InvalidArgumentException::class);
        $b->broadcastFor($other, 'whatever');
        Event::assertNotDispatched(ChainStepAdvanced::class);
    }

    public function test_rejects_an_unmapped_status_instead_of_publishing_false_progress(): void
    {
        Event::fake([ChainStepAdvanced::class]);

        $so = new SalesOrder();
        $so->id = 42;
        $so->so_number = 'SO-202604-0042';

        $this->expectException(\InvalidArgumentException::class);
        app(ChainBroadcaster::class)->broadcastFor($so, 'future_status');
        Event::assertNotDispatched(ChainStepAdvanced::class);
    }

    public function test_durable_staging_failure_is_rethrown_for_transaction_rollback(): void
    {
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordForChain')
            ->once()
            ->andThrow(new \RuntimeException('outbox unavailable'));
        $this->app->instance(OutboxService::class, $outbox);

        $so = new SalesOrder();
        $so->id = 42;
        $so->so_number = 'SO-202604-0042';

        $this->expectException(\RuntimeException::class);
        app(ChainBroadcaster::class)->broadcastFor($so, 'confirmed');
    }
}
