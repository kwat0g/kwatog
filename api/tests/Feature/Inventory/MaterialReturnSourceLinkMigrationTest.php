<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MaterialReturnSourceLinkMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_source_link_backfills_only_exact_one_to_one_issue_matches(): void
    {
        $migration = require database_path('migrations/2026_09_25_230000_add_material_return_source_links_and_idempotency.php');
        $migration->down();

        $user = User::factory()->create();
        $item = Item::factory()->create();
        $location = WarehouseLocation::factory()->create();

        [$uniqueLine, $uniqueMovement] = $this->legacyLineAndMovement(
            'MIS-LINK-UNIQUE', $item, $location, $user, '1.000', '12.3400', '12.34', 'LOT-LINK-UNIQUE',
        );
        [$ambiguousLine] = $this->legacyLineAndMovement(
            'MIS-LINK-AMBIG', $item, $location, $user, '2.000', '7.2500', '14.50', 'LOT-LINK-AMBIG',
        );
        $this->createLegacyMovement($ambiguousLine->slip, $item, $location, $user, '2.000', '7.2500', '14.50', 'LOT-LINK-AMBIG');
        [$mismatchedLine] = $this->legacyLineAndMovement(
            'MIS-LINK-MISMATCH', $item, $location, $user, '3.000', '4.0000', '12.00', 'LOT-LINK-MISMATCH',
            movementCost: '11.99',
        );

        $migration->up();

        $this->assertSame($uniqueMovement->id, (int) DB::table('material_issue_slip_items')->where('id', $uniqueLine->id)->value('stock_movement_id'));
        $this->assertNull(DB::table('material_issue_slip_items')->where('id', $ambiguousLine->id)->value('stock_movement_id'));
        $this->assertNull(DB::table('material_issue_slip_items')->where('id', $mismatchedLine->id)->value('stock_movement_id'));
    }

    /** @return array{MaterialIssueSlipItem, StockMovement} */
    private function legacyLineAndMovement(
        string $slipNumber,
        Item $item,
        WarehouseLocation $location,
        User $user,
        string $quantity,
        string $unitCost,
        string $lineCost,
        string $lot,
        ?string $movementCost = null,
    ): array {
        $slip = MaterialIssueSlip::create([
            'slip_number' => $slipNumber,
            'work_order_id' => null,
            'issued_date' => now()->toDateString(),
            'issued_by' => $user->id,
            'created_by' => $user->id,
            'status' => MaterialIssueStatus::Issued,
            'total_value' => $lineCost,
        ]);
        $line = MaterialIssueSlipItem::create([
            'material_issue_slip_id' => $slip->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_issued' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $lineCost,
            'lot_number' => $lot,
        ]);
        $movement = $this->createLegacyMovement(
            $slip, $item, $location, $user, $quantity, $unitCost, $movementCost ?? $lineCost, $lot,
        );

        return [$line, $movement];
    }

    private function createLegacyMovement(
        MaterialIssueSlip $slip,
        Item $item,
        WarehouseLocation $location,
        User $user,
        string $quantity,
        string $unitCost,
        string $totalCost,
        string $lot,
    ): StockMovement {
        return StockMovement::create([
            'item_id' => $item->id,
            'from_location_id' => $location->id,
            'to_location_id' => null,
            'movement_type' => StockMovementType::MaterialIssue,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'reference_type' => 'material_issue_slip',
            'reference_id' => $slip->id,
            'lot_number' => $lot,
            'created_by' => $user->id,
            'created_at' => now(),
        ]);
    }
}
