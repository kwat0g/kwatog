<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\ChainStepAdvanced;
use App\Common\Services\ChainBroadcaster;
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

    public function test_returns_false_for_unsupported_model(): void
    {
        Event::fake([ChainStepAdvanced::class]);

        $other = new class extends Model {
            protected $table = 'fake';
            public $id = 1;
        };

        $b = app(ChainBroadcaster::class);
        $ok = $b->broadcastFor($other, 'whatever');

        $this->assertFalse($ok);
        Event::assertNotDispatched(ChainStepAdvanced::class);
    }
}
