<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Common\Enums\AlertSeverity;
use App\Common\Enums\AlertType;
use App\Common\Models\Alert;
use App\Common\Notifications\CriticalAlertEmail;
use App\Common\Services\AlertEngineService;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Factories\ItemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AlertsHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_raise_reuses_one_open_condition_and_rearms_after_recovery(): void
    {
        $engine = app(AlertEngineService::class);

        $first = $engine->raise(
            AlertType::StockLow,
            AlertSeverity::Warning,
            'Low stock',
            'First observation',
        );
        $sameCondition = $engine->raise(
            AlertType::StockLow,
            AlertSeverity::Warning,
            'Low stock updated',
            'Second observation',
        );

        $this->assertSame($first->getKey(), $sameCondition->getKey());
        $this->assertSame(1, Alert::query()->whereNull('resolved_at')->count());
        $this->assertSame('Second observation', $first->fresh()->message);

        $first->forceFill(['resolved_at' => now()])->save();
        $rearmed = $engine->raise(
            AlertType::StockLow,
            AlertSeverity::Warning,
            'Low stock rearmed',
            'A new condition cycle',
        );

        $this->assertNotSame($first->getKey(), $rearmed->getKey());
        $this->assertSame(1, Alert::query()->whereNull('resolved_at')->count());
        $this->assertSame(2, Alert::query()->count());
    }

    public function test_successful_engine_check_resolves_a_condition_that_is_no_longer_observed(): void
    {
        $engine = app(AlertEngineService::class);
        $alert = $engine->raise(
            AlertType::StockLow,
            AlertSeverity::Warning,
            'Low stock',
            'The item was low before the check',
        );

        $stats = $engine->runAllChecks();

        $this->assertSame([], $stats['failed']);
        $this->assertNotNull($alert->fresh()->resolved_at);
    }

    public function test_run_statistics_are_scoped_to_alerts_created_by_that_run(): void
    {
        $item = ItemFactory::new()->create([
            'reorder_point' => 10,
            'safety_stock' => 0,
        ]);

        $stats = app(AlertEngineService::class)->runAllChecks();

        $this->assertGreaterThanOrEqual(1, $stats['raised']);
        $this->assertSame(1, $stats['by_type'][AlertType::StockLow->value]);
        $this->assertSame(
            1,
            Alert::query()
                ->where('type', AlertType::StockLow->value)
                ->where('entity_id', $item->getKey())
                ->whereNull('resolved_at')
                ->count(),
        );
    }

    public function test_critical_delivery_records_a_successful_handoff(): void
    {
        Notification::fake();
        $catalog = json_decode((string) DB::table('settings')
            ->where('key', 'alerts.critical.notification_roles')
            ->value('value'), true) ?: [];
        $catalog['scheduler_stale'] = ['system_admin'];
        DB::table('settings')->where('key', 'alerts.critical.notification_roles')->update([
            'value' => json_encode($catalog),
            'updated_at' => now(),
        ]);
        $role = Role::firstOrCreate(['slug' => 'system_admin'], ['name' => 'System Administrator']);
        $user = User::factory()->create([
            'role_id' => $role->getKey(),
            'email' => 'alerts@ogami.test',
            'last_activity' => now(),
            'password_changed_at' => now(),
        ]);

        $alert = app(AlertEngineService::class)->raise(
            AlertType::SchedulerStale,
            AlertSeverity::Critical,
            'Scheduler degraded',
            'The scheduler is not healthy.',
        );

        $fresh = $alert->fresh();
        $this->assertSame('sent', $fresh->email_status);
        $this->assertSame(1, $fresh->email_attempts);
        $this->assertNotNull($fresh->notified_email_at);
        Notification::assertSentTo($user, CriticalAlertEmail::class);
    }

    public function test_critical_delivery_failure_remains_retryable_without_duplicate_attempts(): void
    {
        $settings = app(SettingsService::class);
        $catalog = json_decode((string) DB::table('settings')
            ->where('key', 'alerts.critical.notification_roles')
            ->value('value'), true) ?: [];
        unset($catalog['scheduler_stale']);
        $settings->set('alerts.critical.notification_roles', $catalog);

        $alert = app(AlertEngineService::class)->raise(
            AlertType::SchedulerStale,
            AlertSeverity::Critical,
            'Scheduler degraded',
            'The scheduler is not healthy.',
        );

        $failed = $alert->fresh();
        $this->assertSame('failed', $failed->email_status);
        $this->assertSame(1, $failed->email_attempts);
        $this->assertNotNull($failed->email_next_attempt_at);

        app(AlertEngineService::class)->raise(
            AlertType::SchedulerStale,
            AlertSeverity::Critical,
            'Scheduler degraded',
            'The scheduler is still not healthy.',
        );

        $this->assertSame(1, $alert->fresh()->email_attempts);
    }

    public function test_ap_due_soon_uses_the_inclusive_today_through_window(): void
    {
        $today = Carbon::today();
        $within = Bill::factory()->create([
            'due_date' => $today->copy()->addDays(2)->toDateString(),
        ]);
        $outside = Bill::factory()->create([
            'due_date' => $today->copy()->addDays(4)->toDateString(),
        ]);
        $overdue = Bill::factory()->create([
            'due_date' => $today->copy()->subDay()->toDateString(),
        ]);

        app(AlertEngineService::class)->runAllChecks();

        $this->assertDatabaseHas('alerts', [
            'type' => AlertType::ApDueSoon->value,
            'entity_id' => $within->getKey(),
        ]);
        $this->assertDatabaseMissing('alerts', [
            'type' => AlertType::ApDueSoon->value,
            'entity_id' => $outside->getKey(),
        ]);
        $this->assertDatabaseMissing('alerts', [
            'type' => AlertType::ApDueSoon->value,
            'entity_id' => $overdue->getKey(),
        ]);
    }

    public function test_unread_count_excludes_read_and_recovered_rows_and_rejects_invalid_filter(): void
    {
        $user = User::factory()->withRole('system_admin')->create([
            'last_activity' => now(),
            'password_changed_at' => now(),
        ]);
        $unread = Alert::create([
            'type' => AlertType::StockLow->value,
            'severity' => AlertSeverity::Warning->value,
            'title' => 'Unread',
            'message' => 'Needs review',
        ]);
        Alert::create([
            'type' => AlertType::NoSupplier->value,
            'severity' => AlertSeverity::Warning->value,
            'title' => 'Read',
            'message' => 'Already reviewed',
            'is_read' => true,
        ]);
        Alert::create([
            'type' => AlertType::MachineBreakdown->value,
            'severity' => AlertSeverity::Critical->value,
            'title' => 'Recovered',
            'message' => 'No longer active',
            'resolved_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/alerts/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $this->actingAs($user)
            ->getJson('/api/v1/alerts?is_dismissed=not-a-boolean')
            ->assertStatus(422);

        $this->assertFalse($unread->fresh()->is_read);
    }

    public function test_prune_resolved_does_not_remove_open_conditions(): void
    {
        $old = Alert::create([
            'type' => AlertType::StockLow->value,
            'severity' => AlertSeverity::Warning->value,
            'title' => 'Old resolved',
            'message' => 'Archive me',
            'resolved_at' => now()->subMonths(13),
        ]);
        $open = Alert::create([
            'type' => AlertType::NoSupplier->value,
            'severity' => AlertSeverity::Warning->value,
            'title' => 'Still open',
            'message' => 'Keep me',
        ]);
        $open->forceFill(['created_at' => now()->subMonths(13)])->save();

        $deleted = app(AlertEngineService::class)->pruneResolved(12);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('alerts', ['id' => $old->getKey()]);
        $this->assertDatabaseHas('alerts', ['id' => $open->getKey()]);
    }
}
