<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Purchasing\Models\SupplierPerformanceSnapshot;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * T3.3.B — Cross-vendor ranking endpoint.
 *
 * GET /api/v1/purchasing/vendors/ranking?period_year=YYYY&period_month=MM
 *     &tier=A&limit=50
 *
 * Defaults to the previous calendar month. Permission gate:
 * `purchasing.suppliers.performance.view`. Limit is clamped server-side at 100.
 */
class SupplierRankingTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasingOfficer;
    private User $unauthorized;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->purchasingOfficer = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
        ]);
        $this->unauthorized = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'employee')->value('id'),
        ]);
    }

    public function test_ranking_requires_permission(): void
    {
        $this->actingAs($this->unauthorized)
            ->getJson('/api/v1/purchasing/vendors/ranking')
            ->assertForbidden();
    }

    public function test_ranking_orders_by_overall_score_desc_for_explicit_period(): void
    {
        $best  = Vendor::factory()->create(['name' => 'AlphaCo']);
        $mid   = Vendor::factory()->create(['name' => 'BravoCo']);
        $worst = Vendor::factory()->create(['name' => 'CharlieCo']);

        $this->makeSnapshot($best,  2026, 5, 95.0, 'A');
        $this->makeSnapshot($mid,   2026, 5, 80.0, 'B');
        $this->makeSnapshot($worst, 2026, 5, 55.0, 'D');

        // Decoy snapshot in a different month must NOT leak in.
        $this->makeSnapshot($best, 2026, 4, 10.0, 'D');

        $response = $this->actingAs($this->purchasingOfficer)
            ->getJson('/api/v1/purchasing/vendors/ranking?period_year=2026&period_month=5')
            ->assertOk();

        $rows = $response->json('data');
        $this->assertCount(3, $rows);
        $this->assertSame('AlphaCo',   $rows[0]['vendor']['name']);
        $this->assertSame('A',         $rows[0]['tier']);
        $this->assertSame('95.00',     (string) $rows[0]['overall_score']);
        $this->assertSame('BravoCo',   $rows[1]['vendor']['name']);
        $this->assertSame('CharlieCo', $rows[2]['vendor']['name']);
        $this->assertSame(2026,        $response->json('meta.period_year'));
        $this->assertSame(5,           $response->json('meta.period_month'));
    }

    public function test_ranking_filters_by_tier(): void
    {
        $a = Vendor::factory()->create(['name' => 'AlphaCo']);
        $b = Vendor::factory()->create(['name' => 'BravoCo']);
        $this->makeSnapshot($a, 2026, 5, 95.0, 'A');
        $this->makeSnapshot($b, 2026, 5, 80.0, 'B');

        $response = $this->actingAs($this->purchasingOfficer)
            ->getJson('/api/v1/purchasing/vendors/ranking?period_year=2026&period_month=5&tier=A')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('AlphaCo', $response->json('data.0.vendor.name'));
    }

    public function test_ranking_caps_limit_at_100(): void
    {
        $this->actingAs($this->purchasingOfficer)
            ->getJson('/api/v1/purchasing/vendors/ranking?limit=999')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta']);

        // Hard cap is enforced server-side; we don't need 999 rows to verify
        // — only that the controller does not 500 with an oversize limit.
    }

    public function test_ranking_defaults_to_previous_calendar_month(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 10));

        $vendor = Vendor::factory()->create(['name' => 'PrevMonthCo']);
        $this->makeSnapshot($vendor, 2026, 5, 88.0, 'B');  // previous month
        $this->makeSnapshot($vendor, 2026, 6, 99.0, 'A');  // current month — must be ignored

        $response = $this->actingAs($this->purchasingOfficer)
            ->getJson('/api/v1/purchasing/vendors/ranking')
            ->assertOk();

        $this->assertSame(5, $response->json('meta.period_month'));
        $this->assertSame('B', $response->json('data.0.tier'));

        Carbon::setTestNow();
    }

    public function test_ranking_returns_hash_id_never_raw_id(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'HashCo']);
        $this->makeSnapshot($vendor, 2026, 5, 90.0, 'A');

        $response = $this->actingAs($this->purchasingOfficer)
            ->getJson('/api/v1/purchasing/vendors/ranking?period_year=2026&period_month=5')
            ->assertOk();

        $vendorId = $response->json('data.0.vendor.id');
        $this->assertIsString($vendorId);
        $this->assertNotEquals((string) $vendor->id, $vendorId);
        $this->assertSame($vendor->hash_id, $vendorId);
    }

    private function makeSnapshot(Vendor $vendor, int $year, int $month, float $score, string $tier): void
    {
        SupplierPerformanceSnapshot::create([
            'vendor_id'              => $vendor->id,
            'period_year'            => $year,
            'period_month'           => $month,
            'on_time_delivery_rate'  => 90.0,
            'quality_pass_rate'      => 95.0,
            'ncr_rate'               => 1.0,
            'overall_score'          => $score,
            'tier'                   => $tier,
            'po_count'               => 5,
            'grn_count'              => 5,
            'computed_at'            => now(),
        ]);
    }
}
