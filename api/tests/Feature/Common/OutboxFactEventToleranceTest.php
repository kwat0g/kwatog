<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxDispatcher;
use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\MRP\Jobs\RunAutomaticMrpJob;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * O2C audit 2026-09-25 — the outbox pins the published row version, and a
 * mismatch failed the event forever. SalesOrderConfirmed died whenever MRP
 * touched the order before the worker got to it, so PPC was never told, the
 * customer never got the email and MRP was never queued. Chain-1 fact events
 * now accept a NEWER row; everything else keeps the strict pin.
 */
class OutboxFactEventToleranceTest extends TestCase
{
    use RefreshDatabase;

    private User $ppc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Bus::fake([DispatchOutboxMessage::class, RunAutomaticMrpJob::class]);
        Mail::fake();
        $this->ppc = User::factory()->create(['is_active' => true, 'role_id' => Role::query()->where('slug', 'ppc_head')->value('id')]);
    }

    private function confirmedOrder(): SalesOrder
    {
        $so = SalesOrder::factory()->create();
        $so->forceFill(['status' => 'confirmed'])->save();

        return $so->fresh();
    }

    private function dispatch(OutboxMessage $message): string
    {
        try {
            app(OutboxDispatcher::class)->dispatch((string) $message->getKey());
        } catch (\Throwable) {
            // The dispatcher records the failure on the row; the row is the evidence.
        }

        return (string) OutboxMessage::query()->whereKey($message->getKey())->value('status');
    }

    private function soNoticeSent(SalesOrder $so): bool
    {
        return DB::table('notifications')->where('notifiable_id', $this->ppc->id)
            ->where('type', 'chain.so_confirmed')->where('data', 'like', '%'.$so->so_number.'%')->exists();
    }

    public function test_a_confirmed_order_touched_before_delivery_still_announces_its_confirmation(): void
    {
        $so = $this->confirmedOrder();
        $message = app(OutboxService::class)->record(new SalesOrderConfirmed($so));

        $this->travel(5)->seconds();
        $so->forceFill(['notes' => 'MRP linked a plan'])->save();

        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $this->dispatch($message));
        $this->assertTrue($this->soNoticeSent($so));
    }

    public function test_an_order_cancelled_before_delivery_is_not_announced_as_confirmed(): void
    {
        $so = $this->confirmedOrder();
        $message = app(OutboxService::class)->record(new SalesOrderConfirmed($so));

        $this->travel(5)->seconds();
        $so->forceFill(['status' => 'cancelled'])->save();

        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $this->dispatch($message));
        $this->assertFalse($this->soNoticeSent($so));
        Mail::assertNothingQueued();
    }

    public function test_an_older_row_is_still_refused_even_for_a_fact_event(): void
    {
        $so = $this->confirmedOrder();
        $message = app(OutboxService::class)->record(new SalesOrderConfirmed($so));
        $payload = json_decode((string) DB::table('event_outbox')->where('id', $message->getKey())->value('payload'), true);
        $payload['salesOrder']['version'] = now()->addHour()->toDateTimeString();
        DB::table('event_outbox')->where('id', $message->getKey())->update(['payload' => json_encode($payload)]);

        $this->assertNotSame(OutboxMessage::STATUS_PUBLISHED, $this->dispatch($message));
        $this->assertFalse($this->soNoticeSent($so));
    }

    public function test_events_that_did_not_opt_in_keep_the_strict_version_pin(): void
    {
        $inspection = Inspection::query()->create([
            'inspection_number' => 'QC-T-'.substr(uniqid(), -6),
            'stage' => 'in_process',
            'status' => 'failed',
            'product_id' => \App\Modules\CRM\Models\Product::factory()->create()->id,
            'batch_quantity' => 1,
            'sample_size' => 1,
        ]);
        $message = app(OutboxService::class)->record(new InspectionFailed($inspection));

        $this->travel(5)->seconds();
        $inspection->forceFill(['notes' => 'touched'])->save();

        $this->assertNotSame(OutboxMessage::STATUS_PUBLISHED, $this->dispatch($message));
        $this->assertStringContainsString('changed after publication',
            (string) DB::table('event_outbox')->where('id', $message->getKey())->value('last_error'));
    }
}
