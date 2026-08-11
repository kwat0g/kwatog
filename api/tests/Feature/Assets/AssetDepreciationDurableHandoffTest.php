<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Common\Jobs\DispatchOutboxMessage;
use App\Common\Models\OutboxMessage;
use App\Common\Services\OutboxEventCodec;
use App\Modules\Assets\Enums\AssetStatus;
use App\Modules\Assets\Events\MonthlyDepreciationRequested;
use App\Modules\Assets\Listeners\RunMonthlyDepreciationOnRequested;
use App\Modules\Assets\Models\Asset;
use App\Modules\Auth\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AssetDepreciationDurableHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ChartOfAccountsSeeder::class, RolePermissionSeeder::class, SettingsSeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_explicit_monthly_depreciation_request_is_durable_and_decodable(): void
    {
        Queue::fake();

        $this->artisan('assets:request-monthly-depreciation', [
            '--year' => 2026,
            '--month' => 7,
        ])
            ->expectsOutputToContain('Staged durable asset depreciation request for 2026-07')
            ->assertExitCode(0);

        $outbox = OutboxMessage::query()
            ->where('event_type', MonthlyDepreciationRequested::class)
            ->where('dedupe_key', 'assets-depreciation:2026-07')
            ->firstOrFail();
        $this->assertSame(MonthlyDepreciationRequested::class, $outbox->event_type);
        $this->assertSame('assets-depreciation:2026-07', $outbox->dedupe_key);
        Queue::assertPushed(DispatchOutboxMessage::class, fn (DispatchOutboxMessage $job): bool => $job->outboxId === $outbox->getKey());

        $event = app(OutboxEventCodec::class)->decode(
            MonthlyDepreciationRequested::class,
            (array) $outbox->payload,
        );

        $this->assertInstanceOf(MonthlyDepreciationRequested::class, $event);
        $this->assertSame(2026, $event->year);
        $this->assertSame(7, $event->month);
        $this->assertSame('2026-07', $event->requestId);
    }

    public function test_default_request_targets_the_previous_month_and_invalid_pairs_fail_closed(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-11 12:00:00');

        $this->artisan('assets:request-monthly-depreciation', [
            '--year' => 2026,
        ])
            ->expectsOutput('Both --year and --month must be provided together.')
            ->assertExitCode(1);

        $this->artisan('assets:request-monthly-depreciation')
            ->assertExitCode(0);

        $this->assertDatabaseHas('event_outbox', [
            'event_type' => MonthlyDepreciationRequested::class,
            'dedupe_key' => 'assets-depreciation:2026-07',
        ]);
    }

    public function test_forced_request_creates_a_second_recovery_request_for_the_same_period(): void
    {
        Queue::fake();

        $arguments = ['--year' => 2026, '--month' => 7];
        $this->artisan('assets:request-monthly-depreciation', $arguments)->assertExitCode(0);
        $this->artisan('assets:request-monthly-depreciation', $arguments + ['--force' => true])->assertExitCode(0);

        $this->assertSame(
            2,
            OutboxMessage::query()
                ->where('event_type', MonthlyDepreciationRequested::class)
                ->count(),
        );
    }

    public function test_durable_listener_executes_the_requested_period_through_the_existing_job(): void
    {
        User::factory()->withRole('system_admin')->create();
        $asset = Asset::create([
            'asset_code' => 'AST-DURABLE-001',
            'name' => 'Durable listener asset',
            'category' => 'equipment',
            'acquisition_date' => '2025-01-01',
            'acquisition_cost' => '12000.00',
            'useful_life_years' => 5,
            'salvage_value' => '0.00',
            'status' => AssetStatus::Active->value,
        ]);

        app(RunMonthlyDepreciationOnRequested::class)->handle(
            new MonthlyDepreciationRequested(2025, 12, '2025-12'),
        );

        $this->assertDatabaseHas('asset_depreciations', [
            'asset_id' => $asset->id,
            'period_year' => 2025,
            'period_month' => 12,
            'depreciation_amount' => '200.00',
        ]);
        $this->assertSame('200.00', $asset->fresh()->accumulated_depreciation);
    }
}
