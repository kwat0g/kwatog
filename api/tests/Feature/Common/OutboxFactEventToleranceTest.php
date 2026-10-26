<?php

declare(strict_types=1);

namespace Tests\Feature\Common;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxDispatcher;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\OutboxService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Events\SalesOrderConfirmed;
use App\Modules\CRM\Listeners\NotifyOnSalesOrderConfirmed;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Events\GoodsReceiptNoteCreated;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\MRP\Events\MrpPlanGenerated;
use App\Modules\MRP\Jobs\RunAutomaticMrpJob;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Quality\Events\InspectionFailed;
use App\Modules\Quality\Models\Inspection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\PrivateChannel;
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

    private function soNoticeSent(): bool
    {
        return DB::table('notifications')->where('notifiable_id', $this->ppc->id)
            ->where('type', 'chain.so_confirmed')->exists();
    }

    public function test_a_confirmed_order_touched_before_delivery_still_announces_its_confirmation(): void
    {
        $so = $this->confirmedOrder();
        $message = app(OutboxService::class)->record(new SalesOrderConfirmed($so));

        $this->travel(5)->seconds();
        $so->forceFill(['notes' => 'MRP linked a plan'])->save();

        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $this->dispatch($message));
        $this->assertTrue($this->soNoticeSent());
        $notification = DB::table('notifications')->where('notifiable_id', $this->ppc->id)
            ->where('type', 'chain.so_confirmed')->first();
        $data = json_decode($notification->data, true);
        $this->assertSame('/crm/sales-orders/'.$so->hash_id, $data['link_to']);
        $this->assertSame('sales_order', $data['entity_type']);
        $this->assertSame($so->hash_id, $data['entity_id']);
        $this->assertStringContainsString($so->so_number, $data['title']);

        app(NotifyOnSalesOrderConfirmed::class)
            ->handle(new SalesOrderConfirmed($so));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->ppc->id)
            ->where('type', 'chain.so_confirmed')->count());
    }

    public function test_sales_order_notice_excludes_configured_users_without_schedule_access(): void
    {
        $revoked = User::factory()->create([
            'is_active' => true,
            'role_id' => Role::query()->where('slug', 'ppc_head')->value('id'),
        ]);
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revoked->id,
            'permission_id' => DB::table('permissions')->where('slug', 'production.schedule.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $actor->id,
            'reason' => 'Sales-order notice access regression test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $so = $this->confirmedOrder();
        $message = app(OutboxService::class)->record(new SalesOrderConfirmed($so));

        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $this->dispatch($message));
        $this->assertTrue($this->soNoticeSent());
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $revoked->id,
            'type' => 'chain.so_confirmed',
        ]);
    }

    public function test_sales_order_notice_excludes_configured_planners_without_order_access(): void
    {
        $revoked = User::factory()->create([
            'is_active' => true,
            'role_id' => Role::query()->where('slug', 'production_manager')->value('id'),
        ]);
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $revoked->id,
            'permission_id' => DB::table('permissions')->where('slug', 'crm.sales_orders.view')->value('id'),
            'type' => 'revoke',
            'granted_by' => $actor->id,
            'reason' => 'Sales-order notification record-access regression test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $so = $this->confirmedOrder();
        $message = app(OutboxService::class)->record(new SalesOrderConfirmed($so));

        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $this->dispatch($message));
        $this->assertTrue($this->soNoticeSent());
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $revoked->id,
            'type' => 'chain.so_confirmed',
        ]);
    }

    public function test_sales_order_confirmation_notice_includes_creator_and_excludes_confirming_planner(): void
    {
        $creator = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'sales_officer')->value('id'),
        ]);
        $so = SalesOrder::factory()->create(['created_by' => $creator->id]);
        $so->forceFill(['status' => 'confirmed'])->save();

        app(NotifyOnSalesOrderConfirmed::class)->handle(new SalesOrderConfirmed(
            $so->fresh(),
            actorUserId: $this->ppc->id,
        ));

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $creator->id,
            'type' => 'chain.so_confirmed',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $this->ppc->id,
            'type' => 'chain.so_confirmed',
        ]);
    }

    public function test_sales_order_outbox_payload_without_actor_remains_compatible(): void
    {
        $so = $this->confirmedOrder();
        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode(new SalesOrderConfirmed($so, actorUserId: $this->ppc->id));

        $event = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertSame($this->ppc->id, $event->actorUserId);

        unset($encoded['payload']['actorUserId']);

        $event = $codec->decode($encoded['event_type'], $encoded['payload']);

        $this->assertNull($event->actorUserId);
    }

    public function test_sales_order_production_dashboard_broadcast_contains_only_a_refresh_ping(): void
    {
        $event = new SalesOrderConfirmed($this->confirmedOrder());

        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('private-production.dashboard', $event->broadcastOn()[0]->name);
        $this->assertSame(['refresh' => true], $event->broadcastWith());
    }

    public function test_an_order_cancelled_before_delivery_is_not_announced_as_confirmed(): void
    {
        $so = $this->confirmedOrder();
        $message = app(OutboxService::class)->record(new SalesOrderConfirmed($so));

        $this->travel(5)->seconds();
        $so->forceFill(['status' => 'cancelled'])->save();

        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $this->dispatch($message));
        $this->assertFalse($this->soNoticeSent());
        Mail::assertNothingQueued();
    }

    public function test_created_grn_event_publishes_after_the_grn_advances(): void
    {
        $grn = GoodsReceiptNote::factory()->create(['status' => 'pending_qc']);
        $message = app(OutboxService::class)->record(new GoodsReceiptNoteCreated($grn));

        $this->travel(5)->seconds();
        $grn->forceFill(['status' => 'accepted'])->save();

        $this->assertSame(OutboxMessage::STATUS_PUBLISHED, $this->dispatch($message));
    }

    public function test_an_older_row_is_still_refused_even_for_a_fact_event(): void
    {
        $so = $this->confirmedOrder();
        $message = app(OutboxService::class)->record(new SalesOrderConfirmed($so));
        $payload = json_decode((string) DB::table('event_outbox')->where('id', $message->getKey())->value('payload'), true);
        $payload['salesOrder']['version'] = now()->addHour()->toDateTimeString();
        DB::table('event_outbox')->where('id', $message->getKey())->update(['payload' => json_encode($payload)]);

        $this->assertNotSame(OutboxMessage::STATUS_PUBLISHED, $this->dispatch($message));
        $this->assertFalse($this->soNoticeSent());
    }

    public function test_events_that_did_not_opt_in_keep_the_strict_version_pin(): void
    {
        $inspection = Inspection::query()->create([
            'inspection_number' => 'QC-T-'.substr(uniqid(), -6),
            'stage' => 'in_process',
            'status' => 'failed',
            'product_id' => Product::factory()->create()->id,
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

    public function test_generated_plan_can_refresh_after_a_later_update_but_not_announce_a_cancelled_plan(): void
    {
        $plan = MrpPlan::create([
            'mrp_plan_no' => 'MRP-OUTBOX-1',
            'sales_order_id' => $this->confirmedOrder()->id,
            'generated_by' => $this->ppc->id,
            'version' => 1,
            'status' => 'active',
            'generated_at' => now(),
        ]);
        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode(new MrpPlanGenerated($plan));
        $this->travel(5)->seconds();
        $plan->update(['draft_wo_count' => 1]);

        $event = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertSame(['refresh' => true], $event->broadcastWith());
        $this->assertTrue($event->broadcastWhen());

        $this->travel(5)->seconds();
        $plan->update(['status' => 'cancelled']);
        $event = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertFalse($event->broadcastWhen());
    }

    public function test_payroll_finalization_fact_can_decode_after_bank_status_changes(): void
    {
        $period = PayrollPeriod::factory()->create(['status' => 'finalized']);
        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode(new PayrollPeriodFinalized($period));
        $this->travel(5)->seconds();
        $period->forceFill(['bank_file_status' => 'manual_required'])->save();

        $event = $codec->decode($encoded['event_type'], $encoded['payload']);
        $this->assertInstanceOf(PayrollPeriodFinalized::class, $event);
        $this->assertSame('manual_required', $event->period->bank_file_status->value);
    }
}
