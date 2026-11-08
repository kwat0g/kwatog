<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Accounting\Enums\AccountingPeriodStatus;
use App\Modules\Accounting\Events\AccountingPeriodChanged;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\DashboardCacheGeneration;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingPeriodRealtimeTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();

        $transactions = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $transactions);
        DB::connection()->setTransactionManager($transactions);
        $this->seed(RolePermissionSeeder::class);
        DB::table('event_outbox')->delete();
        DB::table('accounting_periods')->delete();
    }

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public function test_close_and_reopen_emit_content_free_refresh_signals(): void
    {
        $actor = $this->financeUser();
        $service = app(AccountingPeriodService::class);

        $period = $service->close(2026, 8, $actor);
        $this->assertSignal('created', $period);

        DB::table('event_outbox')->delete();
        $period = $service->reopen(2026, 8, $actor, 'Approved correction.');
        $this->assertSignal('status_changed', $period);
    }

    public function test_idempotent_close_does_not_emit_a_second_change_signal(): void
    {
        $actor = $this->financeUser();
        $service = app(AccountingPeriodService::class);
        $service->close(2026, 9, $actor);

        $this->assertSame(1, OutboxMessage::query()->where('event_type', AccountingPeriodChanged::class)->count());

        $service->close(2026, 9, $actor);

        $this->assertSame(1, OutboxMessage::query()->where('event_type', AccountingPeriodChanged::class)->count());
    }

    public function test_scheduled_relock_emits_a_status_change_signal(): void
    {
        $period = AccountingPeriod::query()->create([
            'year' => 2026,
            'month' => 7,
            'status' => AccountingPeriodStatus::Reopened,
        ]);
        DB::table('accounting_periods')->where('id', $period->id)->update([
            'reopened_at' => now()->subHours(60),
        ]);
        DB::table('event_outbox')->delete();

        $this->assertSame(1, app(AccountingPeriodService::class)->relockStaleReopenedPeriods(48));
        $this->assertSignal('status_changed', $period->fresh());
    }

    public function test_finance_dashboard_cache_generation_changes_only_after_period_commit(): void
    {
        $actor = $this->financeUser();
        Cache::put('dashboard:cache:version', 'period-test-before', 3600);

        DB::transaction(function () use ($actor): void {
            app(AccountingPeriodService::class)->close(2026, 10, $actor);

            $this->assertSame('period-test-before', DashboardCacheGeneration::current());
        });

        $this->assertNotSame('period-test-before', DashboardCacheGeneration::current());
    }

    public function test_period_api_returns_server_computed_actions_and_version(): void
    {
        $actor = $this->financeUser();
        $response = $this->actingAs($actor)->postJson('/api/v1/accounting/periods/close', [
            'year' => 2026,
            'month' => 11,
        ])->assertOk();

        $response->assertJsonPath('data.available_actions', ['reopen']);
        $response->assertJsonPath('data.permissions.manage', true);
        $this->assertNotEmpty($response->json('data.version'));
    }

    public function test_reopening_a_period_that_is_already_reopened_returns_conflict(): void
    {
        $actor = $this->financeUser();
        $this->actingAs($actor)->postJson('/api/v1/accounting/periods/close', [
            'year' => 2026,
            'month' => 12,
        ])->assertOk();
        $payload = ['year' => 2026, 'month' => 12, 'reason' => 'Approved correction.'];

        $this->postJson('/api/v1/accounting/periods/reopen', $payload)->assertOk();
        $this->postJson('/api/v1/accounting/periods/reopen', $payload)->assertStatus(409);
    }

    private function assertSignal(string $action, AccountingPeriod $period): void
    {
        $message = OutboxMessage::query()
            ->where('event_type', AccountingPeriodChanged::class)
            ->firstOrFail();
        $event = app(OutboxEventCodec::class)->decode($message->event_type, $message->payload);
        $payload = $event->broadcastWith();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, $event);
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('private-entity.accounting_period.visible', $event->broadcastOn()[0]->name);
        $this->assertSame('accounting_period', $payload['entity_type']);
        $this->assertSame($period->hash_id, $payload['hash_id']);
        $this->assertSame($action, $payload['action']);
        $this->assertSame([
            'refresh', 'event_id', 'entity_type', 'hash_id', 'action', 'version', 'actor_id', 'timestamp',
        ], array_keys($payload));
        $this->assertArrayNotHasKey('closed_by', $payload);
        $this->assertArrayNotHasKey('reopen_reason', $payload);
    }

    private function financeUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
            'is_active' => true,
        ]);
    }
}
