<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Modules\Inventory\Support\DemandBasis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class DemandBasisTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_sets_match_historical_definitions(): void
    {
        // Consumer sets remain distinct pending the owner decision (F-02).
        $this->assertSame(
            ['material_issue', 'delivery', 'scrap'],
            DemandBasis::legacy(DemandBasis::REPLENISHMENT)
        );
        $this->assertSame(
            ['material_issue', 'delivery', 'adjustment_out', 'scrap', 'return_to_vendor'],
            DemandBasis::legacy(DemandBasis::SAFETY_STOCK)
        );
        $this->assertSame(
            ['material_issue'],
            DemandBasis::legacy(DemandBasis::PROJECTION)
        );
    }

    public function test_seeded_settings_resolve_to_legacy_sets(): void
    {
        $basis = app(DemandBasis::class);

        // Migrations seed all three keys, so a migrated DB resolves the legacy
        // sets through settings — behavior identical, now tunable.
        $this->assertSame(
            DemandBasis::legacy(DemandBasis::REPLENISHMENT),
            $basis->movementTypes(DemandBasis::REPLENISHMENT)
        );
        $this->assertSame(
            ['material_issue'],
            $basis->movementTypes(DemandBasis::PROJECTION)
        );
    }

    public function test_unsupported_configured_movements_fail_closed(): void
    {
        app(SettingsService::class)->set('inventory.demand.projection_movement_types', ['material_issue', 'consume'], 'inventory');

        $this->expectException(BusinessRuleException::class);
        app(DemandBasis::class)->movementTypes(DemandBasis::PROJECTION);
    }

    public function test_unknown_consumer_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DemandBasis::legacy('payroll');
    }
}
