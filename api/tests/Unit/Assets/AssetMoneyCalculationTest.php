<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use App\Modules\Assets\Enums\DepreciationMethod;
use App\Modules\Assets\Models\Asset;
use Tests\TestCase;

class AssetMoneyCalculationTest extends TestCase
{
    public function test_straight_line_calculation_uses_decimal_boundaries_without_float_drift(): void
    {
        $asset = Asset::make([
            'acquisition_cost' => '1000.01',
            'salvage_value' => '0.01',
            'accumulated_depreciation' => '0.00',
            'useful_life_years' => 1,
            'depreciation_method' => DepreciationMethod::StraightLine->value,
        ]);

        self::assertSame('83.33', $asset->monthly_depreciation);
        self::assertSame('1000.01', $asset->book_value);
    }

    public function test_declining_balance_calculation_is_capped_at_salvage_value(): void
    {
        $asset = Asset::make([
            'acquisition_cost' => '1000.00',
            'salvage_value' => '200.00',
            'accumulated_depreciation' => '800.00',
            'useful_life_years' => 5,
            'depreciation_method' => DepreciationMethod::DecliningBalance->value,
        ]);

        self::assertSame('0.00', $asset->monthly_depreciation);
        self::assertSame('200.00', $asset->book_value);
    }
}
