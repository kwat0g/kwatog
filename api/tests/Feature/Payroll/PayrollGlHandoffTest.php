<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Common\Services\ChainBottleneckService;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\OutboxService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollGlHandoffStatus;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Events\PayrollGlPostingRequested;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Jobs\PostPayrollToGlJob;
use App\Modules\Payroll\Listeners\PostPayrollToGlOnRequested;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollGlPostingService;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Database\Seeders\GovernmentTableSeeder;
use Database\Seeders\PayrollChartAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PayrollGlHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(GovernmentTableSeeder::class);
        $this->seed(PayrollChartAccountsSeeder::class);
    }

    private function makePeriod(PayrollPeriodStatus $status, bool $withPayroll = false): PayrollPeriod
    {
        $period = PayrollPeriod::factory()->create([
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'payroll_date' => '2026-04-15',
            'is_first_half' => true,
        ]);
        $period->forceFill([
            'status' => $status->value,
            'gl_handoff_status' => $status->isLocked() ? PayrollGlHandoffStatus::Pending->value : PayrollGlHandoffStatus::NotStarted->value,
            'gl_handoff_at' => $status->isLocked() ? now() : null,
        ])->save();

        if ($withPayroll) {
            Payroll::factory()->create([
                'payroll_period_id' => $period->id,
                'basic_pay' => '10000.00',
                'gross_pay' => '10000.00',
                'net_pay' => '10000.00',
                'computed_at' => now(),
            ]);
        }

        return $period->fresh();
    }

    private function recordRequest(PayrollPeriod $period, string $dedupe): object
    {
        $event = new PayrollGlPostingRequested($period->fresh(), 'test_request');
        app(OutboxService::class)->recordForChain(
            $event,
            $period,
            'h2r',
            'payroll_period',
            'gl_handoff',
            $dedupe,
        );

        return app(OutboxEventCodec::class)->decode(
            PayrollGlPostingRequested::class,
            json_decode((string) DB::table('event_outbox')->where('dedupe_key', $dedupe)->value('payload'), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_finalize_stages_pending_gl_handoff_and_durable_request(): void
    {
        Event::fake([PayrollPeriodFinalized::class, PayrollGlPostingRequested::class]);
        $period = $this->makePeriod(PayrollPeriodStatus::Approved);

        $actor = User::factory()->create();
        app(PayrollPeriodService::class)->finalize($period, $actor);

        $this->assertSame(PayrollGlHandoffStatus::Pending, $period->fresh()->gl_handoff_status);
        Event::assertDispatched(PayrollGlPostingRequested::class, 1);

        $outbox = DB::table('event_outbox')
            ->where('event_type', PayrollGlPostingRequested::class)
            ->where('dedupe_key', 'like', 'payroll-gl-finalize:'.$period->id.'%')
            ->first();
        $this->assertNotNull($outbox);
        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $outbox->id,
            'chain' => 'h2r',
            'entity_type' => 'payroll_period',
            'entity_id' => $period->id,
            'step' => 'gl_handoff',
        ]);
    }

    public function test_durable_listener_posts_once_and_records_completed_outcome(): void
    {
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        $period = $this->makePeriod(PayrollPeriodStatus::Finalized, true);

        $event = $this->recordRequest($period, 'test-payroll-gl-post-once');
        $posted = $period->fresh();

        $this->assertInstanceOf(PayrollGlPostingRequested::class, $event);
        $this->assertSame(PayrollGlHandoffStatus::Posted, $posted->gl_handoff_status);
        $this->assertNotNull($posted->journal_entry_id);
        $this->assertSame(1, DB::table('journal_entries')
            ->where('reference_type', 'payroll_period')
            ->where('reference_id', $period->id)
            ->count());
        $this->assertDatabaseHas('chain_listener_runs', [
            'outbox_id' => DB::table('event_outbox')->where('dedupe_key', 'test-payroll-gl-post-once')->value('id'),
            'listener_class' => PostPayrollToGlOnRequested::class,
            'outcome_status' => 'completed',
            'outcome_code' => 'payroll_gl_posted',
        ]);

        app(PostPayrollToGlOnRequested::class)->handle($event);

        $this->assertSame(1, DB::table('journal_entries')
            ->where('reference_type', 'payroll_period')
            ->where('reference_id', $period->id)
            ->count());
    }

    public function test_missing_payroll_gl_account_becomes_manual_and_replayable(): void
    {
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        app(SettingsService::class)->set('accounting.accounts.salary_expense_code', '999999', 'accounting');
        $period = $this->makePeriod(PayrollPeriodStatus::Finalized, true);

        $this->recordRequest($period, 'test-payroll-gl-manual');

        $manual = $period->fresh();
        $this->assertSame(PayrollGlHandoffStatus::ManualRequired, $manual->gl_handoff_status);
        $this->assertStringContainsString('missing', strtolower((string) $manual->gl_handoff_note));
        $this->assertNull($manual->journal_entry_id);
        $this->assertDatabaseHas('chain_listener_runs', [
            'outbox_id' => DB::table('event_outbox')->where('dedupe_key', 'test-payroll-gl-manual')->value('id'),
            'listener_class' => PostPayrollToGlOnRequested::class,
            'outcome_status' => 'manual_required',
            'outcome_code' => 'payroll_gl_posting_manual_required',
        ]);

        app(SettingsService::class)->set('accounting.accounts.salary_expense_code', '5050', 'accounting');
        $event = new PayrollGlPostingRequested($manual, 'operator_retry');
        app(PostPayrollToGlOnRequested::class)->handle($event);

        $this->assertSame(PayrollGlHandoffStatus::Posted, $period->fresh()->gl_handoff_status);
        $this->assertNotNull($period->fresh()->journal_entry_id);
    }

    public function test_disabled_accounting_is_explicitly_not_required(): void
    {
        app(SettingsService::class)->set('modules.accounting', false, 'modules');
        $period = $this->makePeriod(PayrollPeriodStatus::Finalized, true);

        $this->assertNull(app(PayrollGlPostingService::class)->post($period));
        $this->assertSame(PayrollGlHandoffStatus::NotRequired, $period->fresh()->gl_handoff_status);
        $this->assertNull($period->fresh()->journal_entry_id);
    }

    public function test_legacy_gl_job_routes_through_the_durable_handoff(): void
    {
        Queue::fake();
        $period = $this->makePeriod(PayrollPeriodStatus::Finalized, true);

        (new PostPayrollToGlJob($period))->handle(app(PayrollPeriodService::class));

        $this->assertDatabaseHas('event_outbox', [
            'event_type' => PayrollGlPostingRequested::class,
        ]);
        $this->assertDatabaseHas('chain_step_runs', [
            'chain' => 'h2r',
            'entity_type' => 'payroll_period',
            'entity_id' => $period->id,
            'step' => 'gl_handoff',
        ]);
        $this->assertNull($period->fresh()->journal_entry_id);
    }

    public function test_retry_route_requires_accounting_post_permission_and_stages_new_request(): void
    {
        Event::fake([PayrollGlPostingRequested::class]);
        $period = $this->makePeriod(PayrollPeriodStatus::Finalized, true);
        $period->markGlManualRequired('Initial configuration failure.');
        $actor = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->valueOrFail('id'),
        ]);

        $this->actingAs($actor, 'sanctum')
            ->postJson('/api/v1/payroll-periods/'.$period->hash_id.'/retry-gl')
            ->assertAccepted()
            ->assertJsonPath('data.gl_handoff_status', PayrollGlHandoffStatus::Pending->value);

        $this->assertDatabaseHas('event_outbox', [
            'event_type' => PayrollGlPostingRequested::class,
        ]);
        $this->assertSame(PayrollGlHandoffStatus::Pending, $period->fresh()->gl_handoff_status);
    }

    public function test_stale_finalization_model_cannot_bypass_authoritative_status_lock(): void
    {
        app(SettingsService::class)->set('modules.accounting', true, 'modules');
        $period = $this->makePeriod(PayrollPeriodStatus::Finalized, true);
        $stale = $period->fresh();
        $period->forceFill(['status' => PayrollPeriodStatus::Draft->value])->save();

        $this->expectException(\RuntimeException::class);
        app(PayrollGlPostingService::class)->post($stale);
    }

    public function test_replayed_request_after_void_is_a_safe_noop(): void
    {
        $period = $this->makePeriod(PayrollPeriodStatus::Voided, true);
        $event = new PayrollGlPostingRequested($period, 'stale_after_void');

        app(PostPayrollToGlOnRequested::class)->handle($event);

        $this->assertSame(PayrollGlHandoffStatus::NotRequired, $period->fresh()->gl_handoff_status);
        $this->assertSame(0, DB::table('journal_entries')
            ->where('reference_type', 'payroll_period')
            ->where('reference_id', $period->id)
            ->count());
    }

    public function test_finalized_payroll_without_gl_is_a_finance_bottleneck(): void
    {
        $period = $this->makePeriod(PayrollPeriodStatus::Finalized);
        $staleAt = now()->subHours(8);
        $period->forceFill([
            'gl_handoff_status' => PayrollGlHandoffStatus::ManualRequired->value,
            'gl_handoff_note' => 'Accounting setup is incomplete.',
            'gl_handoff_at' => $staleAt,
        ])->save();

        $rows = app(ChainBottleneckService::class)->detect('payroll_gl_without_journal');

        $this->assertCount(1, $rows);
        $this->assertSame('payroll_period', $rows[0]['entity_type']);
        $this->assertSame($period->hash_id, $rows[0]['entity_id']);
        $this->assertSame('PAY-'.$period->id, $rows[0]['doc_number']);
        $this->assertSame(PayrollGlHandoffStatus::ManualRequired->value, $rows[0]['status']);
        $this->assertSame('finance_officer', $rows[0]['audience']);
        $this->assertGreaterThanOrEqual(4, (int) $rows[0]['hours_stuck']);
    }
}
