<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\DefectType;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderOutputService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Round 2 — WO output idempotency cache-key namespacing.
 *
 * The record() idempotency key must be scoped per work order: the same key on
 * a DIFFERENT WO must record a fresh output, never replay another WO's cached
 * row (demo-hardening design §3.2 / Round 2 task r2-6).
 */
class WorkOrderOutputIdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;

    private WorkOrderOutputService $service;

    private User $user;

    private DefectType $defectType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->service = app(WorkOrderOutputService::class);
        $this->defectType = DefectType::create([
            'code' => 'DT-IDEM',
            'name' => 'Idempotency test defect',
            'description' => null,
            'is_active' => true,
        ]);
    }

    private function makeWo(): WorkOrder
    {
        $product = Product::create([
            'part_number' => 'FG-IDEM-'.substr(uniqid(), -4),
            'name' => 'Idem FG',
            'unit_of_measure' => 'pcs',
            'standard_cost' => 10.00,
            'is_active' => true,
        ]);

        return WorkOrder::create([
            'wo_number' => 'WO-IDEM-'.substr(uniqid(), -5),
            'product_id' => $product->id,
            'status' => WorkOrderStatus::InProgress->value,
            'quantity_target' => 100,
            'quantity_produced' => 0,
            'quantity_good' => 0,
            'quantity_rejected' => 0,
            'planned_start' => now(),
            'planned_end' => now()->addDay(),
            'actual_start' => now()->subHour(),
            'machine_id' => null,
            'created_by' => $this->user->id,
        ]);
    }

    private function payload(): array
    {
        return [
            'good_count' => 0,
            'reject_count' => 5,
            'defects' => [['defect_type_id' => $this->defectType->id, 'count' => 5]],
        ];
    }

    public function test_same_idempotency_key_on_same_wo_replays_cached_output(): void
    {
        $wo = $this->makeWo();

        $first = $this->service->record($wo, $this->payload(), $this->user->id, 'shift-A');
        $second = $this->service->record($wo->fresh(), $this->payload(), $this->user->id, 'shift-A');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $wo->outputs()->count());
    }

    public function test_same_key_replays_durably_after_cache_flush_and_terminal_wo(): void
    {
        $wo = $this->makeWo();

        $first = $this->service->record($wo, $this->payload(), $this->user->id, 'durable-key');
        Cache::flush();
        $wo->forceFill(['status' => WorkOrderStatus::Completed->value])->save();

        $replay = $this->service->record($wo, $this->payload(), $this->user->id, 'durable-key');

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(1, $wo->fresh()->outputs()->count());
    }

    public function test_same_key_with_different_payload_is_rejected(): void
    {
        $wo = $this->makeWo();
        $this->service->record($wo, $this->payload(), $this->user->id, 'conflict-key');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('different production output payload');
        $this->service->record($wo->fresh(), $this->payload() + ['remarks' => 'changed'], $this->user->id, 'conflict-key');
    }

    public function test_persists_key_and_canonical_fingerprint(): void
    {
        $wo = $this->makeWo();
        $payload = $this->payload() + [
            'shift' => 'A',
            'remarks' => 'Recorded at station 4',
            'defects' => [
                ['defect_type_id' => $this->defectType->id, 'count' => 5],
            ],
        ];

        $output = $this->service->record($wo, $payload, $this->user->id, 'persisted-key');

        $this->assertSame('persisted-key', $output->idempotency_key);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $output->idempotency_fingerprint);
        $this->assertDatabaseHas('work_order_outputs', [
            'id' => $output->id,
            'idempotency_key' => 'persisted-key',
            'idempotency_fingerprint' => $output->idempotency_fingerprint,
        ]);
    }

    public function test_stale_in_progress_model_cannot_record_after_wo_becomes_terminal(): void
    {
        $wo = $this->makeWo();
        $stale = $wo->fresh();
        $wo->forceFill(['status' => WorkOrderStatus::Completed->value])->save();

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('Only in-progress work orders');
        $this->service->record($stale, $this->payload(), $this->user->id, 'terminal-key');
    }

    public function test_same_idempotency_key_on_different_wo_creates_new_output(): void
    {
        $woA = $this->makeWo();
        $woB = $this->makeWo();

        $first = $this->service->record($woA, $this->payload(), $this->user->id, 'shift-A');
        $second = $this->service->record($woB, $this->payload(), $this->user->id, 'shift-A');

        $this->assertNotSame($first->id, $second->id,
            'A key on a different WO must not replay the first WO output (Round 2 cache-key namespacing).');
        $this->assertSame(1, $woA->outputs()->count());
        $this->assertSame(1, $woB->outputs()->count());
    }
}
