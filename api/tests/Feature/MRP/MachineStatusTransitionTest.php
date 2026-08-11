<?php

declare(strict_types=1);

namespace Tests\Feature\MRP;

use App\Modules\MRP\Enums\MachineStatus;
use App\Modules\MRP\Exceptions\IllegalStatusTransitionException;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Services\MachineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MachineStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_revalidates_stale_route_model_inside_locked_transaction(): void
    {
        $machine = Machine::factory()->create(['status' => MachineStatus::Idle->value]);
        $stale = $machine->fresh();

        Machine::query()->whereKey($machine->id)->update([
            'status' => MachineStatus::Maintenance->value,
        ]);

        $this->expectException(IllegalStatusTransitionException::class);

        app(MachineService::class)->transitionStatus($stale, MachineStatus::Running);
    }
}
