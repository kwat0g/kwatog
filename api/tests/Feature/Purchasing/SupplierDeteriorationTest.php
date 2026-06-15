<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Services\NotificationService;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Events\SupplierPerformanceComputed;
use App\Modules\Purchasing\Listeners\AlertOnSupplierDeterioration;
use App\Modules\Purchasing\Models\SupplierPerformanceSnapshot;
use App\Modules\Purchasing\Services\SupplierPerformanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class SupplierDeteriorationTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $purchasingRole = Role::where('slug', 'purchasing_officer')->firstOrFail();
        $this->officer = User::factory()->create([
            'role_id'   => $purchasingRole->id,
            'is_active' => true,
        ]);
    }

    public function test_compute_dispatches_supplier_performance_computed_event(): void
    {
        Event::fake([SupplierPerformanceComputed::class]);

        $vendor = Vendor::factory()->create();
        app(SupplierPerformanceService::class)->compute($vendor, 2026, 5);

        Event::assertDispatched(
            SupplierPerformanceComputed::class,
            fn (SupplierPerformanceComputed $e) => $e->snapshot->vendor_id === $vendor->id
        );
    }

    public function test_listener_notifies_purchasing_officers_on_score_drop_of_20_or_more(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'DropCo']);

        // Prior month snapshot: 90.
        SupplierPerformanceSnapshot::create([
            'vendor_id'     => $vendor->id,
            'period_year'   => 2026,
            'period_month'  => 4,
            'overall_score' => 90.0,
            'tier'          => 'A',
            'po_count'      => 5,
            'grn_count'     => 5,
            'computed_at'   => now()->subMonth(),
        ]);

        // Current month snapshot the listener will inspect: 65 (drop = 25).
        $current = SupplierPerformanceSnapshot::create([
            'vendor_id'     => $vendor->id,
            'period_year'   => 2026,
            'period_month'  => 5,
            'overall_score' => 65.0,
            'tier'          => 'C',
            'po_count'      => 5,
            'grn_count'     => 5,
            'computed_at'   => now(),
        ]);

        $captured = null;
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('send')
            ->once()
            ->andReturnUsing(function ($recipients, string $type, array $data) use (&$captured) {
                $captured = compact('recipients', 'type', 'data');
            });

        (new AlertOnSupplierDeterioration($notifications))
            ->handle(new SupplierPerformanceComputed($current));

        $this->assertNotNull($captured, 'NotificationService::send must be called');
        $this->assertSame('purchasing.supplier_deterioration', $captured['type']);
        $this->assertStringContainsString('DropCo', $captured['data']['title'].$captured['data']['message']);

        // Recipient set must include our purchasing officer.
        $ids = collect($captured['recipients'])->pluck('id')->all();
        $this->assertContains($this->officer->id, $ids);
    }

    public function test_listener_does_not_notify_when_drop_is_less_than_20(): void
    {
        $vendor = Vendor::factory()->create();

        SupplierPerformanceSnapshot::create([
            'vendor_id' => $vendor->id, 'period_year' => 2026, 'period_month' => 4,
            'overall_score' => 90.0, 'tier' => 'A',
            'po_count' => 5, 'grn_count' => 5, 'computed_at' => now()->subMonth(),
        ]);
        $current = SupplierPerformanceSnapshot::create([
            'vendor_id' => $vendor->id, 'period_year' => 2026, 'period_month' => 5,
            'overall_score' => 75.0, 'tier' => 'B',
            'po_count' => 5, 'grn_count' => 5, 'computed_at' => now(),
        ]);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('send');

        (new AlertOnSupplierDeterioration($notifications))
            ->handle(new SupplierPerformanceComputed($current));
    }

    public function test_listener_does_not_notify_when_no_prior_month_snapshot(): void
    {
        $vendor = Vendor::factory()->create();
        $current = SupplierPerformanceSnapshot::create([
            'vendor_id' => $vendor->id, 'period_year' => 2026, 'period_month' => 5,
            'overall_score' => 50.0, 'tier' => 'D',
            'po_count' => 5, 'grn_count' => 5, 'computed_at' => now(),
        ]);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldNotReceive('send');

        (new AlertOnSupplierDeterioration($notifications))
            ->handle(new SupplierPerformanceComputed($current));
    }

    public function test_listener_swallows_throwables(): void
    {
        $vendor = Vendor::factory()->create();
        $current = SupplierPerformanceSnapshot::create([
            'vendor_id' => $vendor->id, 'period_year' => 2026, 'period_month' => 5,
            'overall_score' => 30.0, 'tier' => 'D',
            'po_count' => 5, 'grn_count' => 5, 'computed_at' => now(),
        ]);
        SupplierPerformanceSnapshot::create([
            'vendor_id' => $vendor->id, 'period_year' => 2026, 'period_month' => 4,
            'overall_score' => 90.0, 'tier' => 'A',
            'po_count' => 5, 'grn_count' => 5, 'computed_at' => now()->subMonth(),
        ]);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('send')->andThrow(new \RuntimeException('boom'));

        // Must NOT bubble.
        (new AlertOnSupplierDeterioration($notifications))
            ->handle(new SupplierPerformanceComputed($current));

        $this->assertTrue(true);
    }
}
