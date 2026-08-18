<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
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
 * Idempotent via stock_movements.journal_entry_id. Accounting-disabled and
 * non-value-changing movements are explicitly marked not_required. A
 * value-changing movement that cannot be posted because of missing Accounting
 * setup is committed with a manual_required handoff, then exposed through the
 * durable outbox and narrow listener replay path.
 */
class MovementGlPostingService
{
    private const MANUAL_MESSAGE = 'This stock movement changed inventory value but could not be posted to the General Ledger. Fix the Accounting configuration or posting period, then replay the handoff.';

    /** @var list<StockMovementType> */
    private const NON_GL_TYPES = [
        StockMovementType::GrnReceipt,
        StockMovementType::Transfer,
        StockMovementType::Opening,
        StockMovementType::Delivery,
    ];

    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly SettingsService $settings,
        private readonly GrnGlPostingService $grnPosting,
    ) {}

    public function postFor(StockMovement $movement): ?int
    {
        $post = fn (): ?int => $this->postForLocked($movement);

        return DB::transactionLevel() === 0 ? DB::transaction($post) : $post();
    }

    /** Retry only the movement → GL handoff; stock quantity never changes. */
    public function retry(StockMovement $movement): StockMovement
    {
        try {
            return DB::transaction(function () use ($movement): StockMovement {
                $locked = StockMovement::query()
                    ->whereKey($movement->id)
                    ->lockForUpdate()
                    ->first();
                if (! $locked) {
                    throw new BusinessRuleException('The stock movement no longer exists.');
                }

                $this->postForLocked($locked);

                return $locked->fresh();
            });
        } catch (BusinessRuleException $e) {
            $this->markManual($movement->id);
            throw $e;
        }
    }

    /** Persist the safe operator-facing state for a failed GL handoff. */
    public function markManual(int $movementId, ?string $message = null): void
    {
        DB::transaction(function () use ($movementId, $message): void {
            $movement = StockMovement::query()->whereKey($movementId)->lockForUpdate()->first();
            if (! $movement || $movement->journal_entry_id !== null || $this->isNotRequired($movement)) {
                return;
            }

            $movement->forceFill([
                'gl_handoff_status' => MovementGlHandoffStatus::ManualRequired->value,
                'gl_handoff_message' => $message ?? self::MANUAL_MESSAGE,
                'gl_handoff_at' => now(),
            ])->save();
        });
    }

    private function postForLocked(StockMovement $movement): ?int
    {
        $type = $movement->movement_type;
        if (in_array($type, self::NON_GL_TYPES, true)) {
            $this->markNotRequired($movement, 'movement_type_not_posted_to_gl');
            return null;
        }
        if ($movement->journal_entry_id) {
            $this->markGenerated($movement, (int) $movement->journal_entry_id);
            return (int) $movement->journal_entry_id;
        }

        $enabled = $this->settings->get('modules.accounting', false);
        if ($enabled !== true) {
            $this->markNotRequired($movement, 'accounting_module_disabled');
            return null;
        }
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('accounts')) {
            $this->markManual($movement->id, 'Accounting tables are not available yet. Run the migration, then replay this handoff.');
            return null;
        }

        $value = Money::round2((string) $movement->total_cost);
        if (Money::isZero($value)) {
            $this->markNotRequired($movement, 'zero_value_movement');
            return null;
        }

        $item = Item::query()->whereKey($movement->item_id)->first();
        if (! $item) {
            Log::warning('MovementGlPostingService: item not found; manual handoff required', ['movement_id' => $movement->id]);
            $this->markManual($movement->id);
            return null;
        }

        try {
            [$inventoryCode, $offsetCode, $inventoryIsDebit] = $this->mapping($type, $item);
        } catch (BusinessRuleException $e) {
            Log::warning('MovementGlPostingService: required GL mapping is missing', [
                'movement_id' => $movement->id,
                'error' => $e->getMessage(),
            ]);
            $this->markManual($movement->id);
            return null;
        }

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
                $this->markManual($movement->id);
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

        try {
            $je = $this->journals->create([
                'date'           => now()->toDateString(),
                'description'    => 'Stock movement #' . $movement->id,
                'reference_type' => 'stock_movement',
                'reference_id'   => $movement->id,
                'lines'          => $lines,
            ]);
        } catch (BusinessRuleException $e) {
            // A physical stock movement is still a valid warehouse fact when
            // Accounting is temporarily blocked (for example by a closed
            // posting period). Keep that fact committed and leave a durable,
            // operator-replayable GL handoff instead of losing the movement.
            Log::warning('MovementGlPostingService: journal creation requires manual handoff', [
                'movement_id' => $movement->id,
                'error' => $e->getMessage(),
            ]);
            $this->markManual($movement->id);
            return null;
        }

        // Promote the system-generated draft through the canonical accounting
        // lifecycle; no direct journal_entries mutation is permitted here.
        $this->journals->postSystem($je);

        $this->markGenerated($movement, (int) $je->id);

        return (int) $je->id;
    }

    private function markGenerated(StockMovement $movement, int $journalEntryId): void
    {
        $movement->forceFill([
            'journal_entry_id' => $journalEntryId,
            'gl_handoff_status' => MovementGlHandoffStatus::Generated->value,
            'gl_handoff_message' => null,
            'gl_handoff_at' => now(),
        ])->save();
    }

    private function markNotRequired(StockMovement $movement, string $reason): void
    {
        if ($movement->journal_entry_id !== null) {
            return;
        }

        $movement->forceFill([
            'gl_handoff_status' => MovementGlHandoffStatus::NotRequired->value,
            'gl_handoff_message' => null,
            'gl_handoff_at' => now(),
        ])->save();

        Log::info('MovementGlPostingService: GL handoff not required', [
            'movement_id' => $movement->id,
            'reason' => $reason,
        ]);
    }

    private function isNotRequired(StockMovement $movement): bool
    {
        $type = $movement->movement_type;

        return in_array($type, self::NON_GL_TYPES, true)
            || Money::isZero(Money::round2((string) $movement->total_cost))
            || $this->settings->get('modules.accounting', false) !== true;
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
            // Left unmapped: a StockMovementType with no GL mapping means a new
            // enum case shipped without its posting rule. That is a developer
            // omission to fix, and a 422 would hide it behind a form error.
            default => throw new \RuntimeException("No GL mapping for movement type {$type->value}"),
        };
    }
}
