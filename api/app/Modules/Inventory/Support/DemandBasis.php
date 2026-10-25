<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Common\Exceptions\BusinessRuleException;
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

    /** @return list<string> */
    public static function eligibleMovementTypes(): array
    {
        return ['material_issue', 'delivery', 'adjustment_out', 'scrap', 'return_to_vendor'];
    }

    public function __construct(private readonly SettingsService $settings) {}

    /** @return list<string> */
    public static function legacy(string $consumer): array
    {
        return match ($consumer) {
            self::REPLENISHMENT => ['material_issue', 'delivery', 'scrap'],
            self::SAFETY_STOCK => ['material_issue', 'delivery', 'adjustment_out', 'scrap', 'return_to_vendor'],
            // These two historic values are not StockMovementType cases. The
            // seeded setting is migrated to this effective legacy behavior.
            self::PROJECTION => ['material_issue'],
            default => throw new InvalidArgumentException("Unknown demand consumer '{$consumer}'."),
        };
    }

    /** @return list<string> */
    public function movementTypes(string $consumer): array
    {
        $configured = $this->settings->get("inventory.demand.{$consumer}_movement_types");
        if ($configured !== null && $configured !== []) {
            return $this->validateConfiguredTypes($configured, "inventory.demand.{$consumer}_movement_types");
        }

        // Honor customizations made through the pre-existing safety-stock key.
        if ($consumer === self::SAFETY_STOCK) {
            $legacyKey = $this->settings->get('inventory.safety_stock.issue_movement_types');
            if ($legacyKey !== null && $legacyKey !== []) {
                return $this->validateConfiguredTypes($legacyKey, 'inventory.safety_stock.issue_movement_types');
            }
        }

        return self::legacy($consumer);
    }

    /** @return list<string> */
    private function validateConfiguredTypes(mixed $configured, string $key): array
    {
        if (! is_array($configured) || $configured === []) {
            throw new BusinessRuleException("Required setting {$key} is missing or invalid.");
        }

        foreach ($configured as $type) {
            if (! is_string($type) || ! in_array($type, self::eligibleMovementTypes(), true)) {
                throw new BusinessRuleException("Setting {$key} contains an unsupported demand movement type.");
            }
        }

        return array_values(array_unique($configured));
    }
}
