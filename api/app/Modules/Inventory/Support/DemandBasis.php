<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Common\Services\SettingsService;
use InvalidArgumentException;

/**
 * F-02 — the ONE place demand movement-type sets live.
 *
 * Three consumers historically defined "demand" three different ways
 * (replenishment ≠ safety-stock ≠ stock-out projection). Each consumer keeps
 * its legacy set as the default (behavior unchanged), now tunable via one
 * settings key per consumer instead of two hardcoded lists and one legacy key.
 * Whether the three sets should become ONE shared set is an owner decision
 * (it changes safety-stock and projection outputs) — see docs/dss/FINDINGS.md.
 */
final class DemandBasis
{
    public const REPLENISHMENT = 'replenishment';

    public const SAFETY_STOCK = 'safety_stock';

    public const PROJECTION = 'projection';

    public function __construct(private readonly SettingsService $settings) {}

    /** @return list<string> */
    public static function legacy(string $consumer): array
    {
        return match ($consumer) {
            self::REPLENISHMENT => ['material_issue', 'delivery', 'scrap'],
            self::SAFETY_STOCK => ['material_issue', 'delivery', 'adjustment_out', 'scrap', 'return_to_vendor'],
            // NOTE: `consume` and `production_issue` match no StockMovementType
            // enum case — the projection effectively counts material_issue only
            // today. Preserved verbatim (F-15); changing it is a behavior
            // change for Wave 1, not a drive-by fix.
            self::PROJECTION => ['material_issue', 'consume', 'production_issue'],
            default => throw new InvalidArgumentException("Unknown demand consumer '{$consumer}'."),
        };
    }

    /** @return list<string> */
    public function movementTypes(string $consumer): array
    {
        $configured = $this->settings->get("inventory.demand.{$consumer}_movement_types");
        if (is_array($configured) && $configured !== []) {
            return array_values($configured);
        }

        // Honor customizations made through the pre-existing safety-stock key.
        if ($consumer === self::SAFETY_STOCK) {
            $legacyKey = $this->settings->get('inventory.safety_stock.issue_movement_types');
            if (is_array($legacyKey) && $legacyKey !== []) {
                return array_values($legacyKey);
            }
        }

        return self::legacy($consumer);
    }
}
