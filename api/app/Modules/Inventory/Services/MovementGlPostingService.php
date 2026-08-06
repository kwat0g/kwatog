<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * F-05 — posts a GL journal entry for every value-changing stock movement that
 * GrnGlPostingService does not own.
 *
 * Type → offset account mapping:
 *   AdjustmentIn / AdjustmentOut / CycleCount → inventory_adjustment_code (COGS)
 *   MaterialIssue / Scrap                     → material_consumption_code
 *   ReturnToVendor                            → grni_code
 *   ProductionReceipt                         → material_consumption_code (reversal)
 *
 * GrnReceipt is posted by GrnGlPostingService; Transfer and Opening have no
 * ledger impact (location moves / opening balances post their own); Delivery's
 * COGS recognition is a chain-1 decision handled by the delivery/invoice
 * flow, so it is intentionally left alone here.
 *
 * Idempotent via stock_movements.journal_entry_id. Skips (with a log) when the
 * accounting flag is off, the tables are missing, the movement has no value, or
 * the configured account is absent from the COA — an availability-relevant
 * movement must never die on a GL configuration gap; the gap is surfaced in
 * the logs for an operator to fix.
 */
class MovementGlPostingService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly SettingsService $settings,
        private readonly GrnGlPostingService $grnPosting,
    ) {}

    public function postFor(StockMovement $movement): ?int
    {
        $type = $movement->movement_type;
        if (in_array($type, [
            StockMovementType::GrnReceipt,
            StockMovementType::Transfer,
            StockMovementType::Opening,
            StockMovementType::Delivery,
        ], true)) {
            return null;
        }
        if ($movement->journal_entry_id) {
            return (int) $movement->journal_entry_id;
        }

        $enabled = $this->settings->get('modules.accounting', false);
        if ($enabled !== true) {
            return null;
        }
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('accounts')) {
            return null;
        }

        $value = Money::round2((string) $movement->total_cost);
        if (Money::isZero($value)) {
            return null;
        }

        $item = Item::query()->whereKey($movement->item_id)->first();
        if (! $item) {
            Log::warning('MovementGlPostingService: item not found; skipping', ['movement_id' => $movement->id]);
            return null;
        }

        [$inventoryCode, $offsetCode, $inventoryIsDebit] = $this->mapping($type, $item);

        $accountIds = DB::table('accounts')
            ->whereIn('code', array_unique([$inventoryCode, $offsetCode]))
            ->pluck('id', 'code');

        foreach (['inventory' => $inventoryCode, 'offset' => $offsetCode] as $side => $code) {
            if (! isset($accountIds[$code])) {
                Log::error('MovementGlPostingService: configured account missing from COA', [
                    'movement_id' => $movement->id,
                    'side' => $side,
                    'missing_code' => $code,
                ]);
                return null;
            }
        }

        $description = sprintf('%s — %s', $movement->reference_type ?? 'stock movement', $type->value);
        $lines = $inventoryIsDebit
            ? [
                ['account_id' => $accountIds[$inventoryCode], 'debit' => $value, 'credit' => '0.00', 'description' => $description],
                ['account_id' => $accountIds[$offsetCode],    'debit' => '0.00', 'credit' => $value, 'description' => $description],
            ]
            : [
                ['account_id' => $accountIds[$inventoryCode], 'debit' => '0.00', 'credit' => $value, 'description' => $description],
                ['account_id' => $accountIds[$offsetCode],    'debit' => $value, 'credit' => '0.00', 'description' => $description],
            ];

        return DB::transaction(function () use ($movement, $lines) {
            $je = $this->journals->create([
                'date'           => now()->toDateString(),
                'description'    => 'Stock movement #' . $movement->id,
                'reference_type' => 'stock_movement',
                'reference_id'   => $movement->id,
                'lines'          => $lines,
            ]);

            // Promote draft → posted directly (system-generated post; mirrors
            // GrnGlPostingService).
            DB::table('journal_entries')->where('id', $je->id)->update([
                'status'    => 'posted',
                'posted_at' => now(),
                'updated_at' => now(),
            ]);

            $movement->journal_entry_id = $je->id;
            $movement->save();

            return (int) $je->id;
        });
    }

    /** @return array{0: string, 1: string, 2: bool} inventoryCode, offsetCode, inventoryIsDebit */
    private function mapping(StockMovementType $type, Item $item): array
    {
        $inventoryCode = $this->grnPosting->inventoryAccountCode($item);

        return match ($type) {
            StockMovementType::AdjustmentIn,
            StockMovementType::CycleCount => [
                $inventoryCode,
                $this->settings->requiredString('accounting.accounts.inventory_adjustment_code'),
                true,  // DR inventory, CR COGS
            ],
            StockMovementType::AdjustmentOut => [
                $inventoryCode,
                $this->settings->requiredString('accounting.accounts.inventory_adjustment_code'),
                false, // DR COGS, CR inventory
            ],
            StockMovementType::MaterialIssue,
            StockMovementType::Scrap => [
                $inventoryCode,
                $this->settings->requiredString('accounting.accounts.material_consumption_code'),
                false, // DR consumption, CR inventory
            ],
            StockMovementType::ReturnToVendor => [
                $inventoryCode,
                $this->settings->requiredString('accounting.accounts.grni_code'),
                false, // DR GRNI, CR inventory
            ],
            StockMovementType::ProductionReceipt => [
                $inventoryCode,
                $this->settings->requiredString('accounting.accounts.material_consumption_code'),
                true,  // DR inventory, CR consumption (reversal)
            ],
            default => throw new \RuntimeException("No GL mapping for movement type {$type->value}"),
        };
    }
}
