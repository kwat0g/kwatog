<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\Statements\TrialBalanceService;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REC-05 — go-live opening balances.
 *
 * Three capabilities so migrated books can be proven equal to the legacy
 * source books:
 *   - loadGl(): turn a legacy trial balance into ONE posted opening journal
 *     entry, rejecting the load if the legacy TB is itself unbalanced.
 *   - loadStock(): seed on-hand inventory at a location with an explicit cost
 *     basis (sets weighted_avg_cost) via a StockMovementType::Opening receipt.
 *   - trialBalanceMatch(): diff the system TB against the submitted legacy TB
 *     per account so the migration can be reconciled before go-live.
 *
 * Deferred (needs REC-03 master data): open-invoice / open-bill (AR/AP)
 * importers so aging/dunning start correct.
 */
class OpeningBalanceService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly TrialBalanceService $trialBalance,
        private readonly StockMovementService $stock,
    ) {}

    /**
     * Post the opening-balance journal entry from a legacy trial balance.
     *
     * @param array{date: string, lines: array<int, array{account_id: mixed, debit?: string|float, credit?: string|float}>} $data
     */
    public function loadGl(array $data, User $by): JournalEntry
    {
        $lines = $data['lines'] ?? [];
        if (count($lines) < 2) {
            throw new BusinessRuleException('Opening balances require at least two account lines.');
        }

        // Pre-check the legacy TB nets to zero, with a clear message. (create()
        // re-validates, but this rejects before consuming a document sequence.)
        $debit = Money::zero();
        $credit = Money::zero();
        foreach ($lines as $l) {
            $debit  = Money::add($debit,  Money::round2((string) ($l['debit']  ?? '0')));
            $credit = Money::add($credit, Money::round2((string) ($l['credit'] ?? '0')));
        }
        if (Money::cmp($debit, $credit) !== 0) {
            throw new BusinessRuleException("Legacy trial balance is unbalanced: debit {$debit} != credit {$credit}. Fix the source TB before loading.");
        }

        return DB::transaction(function () use ($data, $lines, $by) {
            $je = $this->journals->create([
                'date'           => $data['date'],
                'description'    => 'Opening balances as of '.$data['date'],
                'reference_type' => 'opening_balance',
                'lines'          => $lines,
            ], $by);

            return $this->journals->post($je, $by);
        });
    }

    /**
     * Seed opening stock at a location. Each row: {item_id, quantity, unit_cost}.
     * Uses a StockMovementType::Opening receipt so the destination WAC is set to
     * the provided cost basis (StockMovementService owns the WAC math).
     *
     * @param array<int, array{item_id: mixed, quantity: string|float, unit_cost: string|float}> $rows
     * @return array{count: int, total_value: string}
     */
    public function loadStock(array $rows, mixed $locationId, User $by): array
    {
        $locId = is_numeric($locationId)
            ? (int) $locationId
            : HashIdFilter::decode((string) $locationId, WarehouseLocation::class);
        if (! $locId || ! WarehouseLocation::query()->whereKey($locId)->exists()) {
            throw new BusinessRuleException('Invalid warehouse location for opening stock.');
        }

        return DB::transaction(function () use ($rows, $locId, $by) {
            $count = 0;
            $totalValue = Money::zero();

            foreach ($rows as $row) {
                $itemId = is_numeric($row['item_id'] ?? null)
                    ? (int) $row['item_id']
                    : HashIdFilter::decode((string) ($row['item_id'] ?? ''), \App\Modules\Inventory\Models\Item::class);
                if (! $itemId) {
                    throw new BusinessRuleException('Invalid item in opening-stock row.');
                }

                $qty  = (string) $row['quantity'];
                $cost = (string) $row['unit_cost'];
                if (bccomp($qty, '0', 3) <= 0) {
                    throw new BusinessRuleException("Opening-stock quantity must be positive for item {$itemId}.");
                }

                $this->stock->move(new StockMovementInput(
                    type: StockMovementType::Opening,
                    itemId: $itemId,
                    quantity: $qty,
                    fromLocationId: null,
                    toLocationId: $locId,
                    unitCost: $cost,
                    referenceType: 'opening_balance',
                    referenceId: null,
                    remarks: 'Opening stock load',
                    createdBy: $by->id,
                ));

                $count++;
                $totalValue = Money::add($totalValue, Money::round2(bcmul($qty, $cost, 4)));
            }

            return ['count' => $count, 'total_value' => $totalValue];
        });
    }

    /**
     * Reconcile the system trial balance against a submitted legacy TB.
     *
     * @param array<int, array{account_id: mixed, debit?: string|float, credit?: string|float}> $legacyTb
     * @return array{balanced: bool, rows: array<int, array<string, mixed>>, legacy_total_debit: string, legacy_total_credit: string, system_total_debit: string, system_total_credit: string}
     */
    public function trialBalanceMatch(array $legacyTb, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ?? Carbon::now();
        // Cumulative system TB from the dawn of the ledger to as-of.
        $system = $this->trialBalance->generate(Carbon::create(2000, 1, 1), $asOf);

        // Index system rows by account code.
        $sysByCode = [];
        foreach ($system['accounts'] as $a) {
            $sysByCode[$a['code']] = $a;
        }

        // Resolve each legacy line's account code.
        $rows = [];
        $legacyDebit = Money::zero();
        $legacyCredit = Money::zero();
        $seenCodes = [];

        foreach ($legacyTb as $l) {
            $account = $this->resolveAccount($l['account_id'] ?? null);
            $ld = Money::round2((string) ($l['debit'] ?? '0'));
            $lc = Money::round2((string) ($l['credit'] ?? '0'));
            $legacyDebit  = Money::add($legacyDebit, $ld);
            $legacyCredit = Money::add($legacyCredit, $lc);

            $sys = $account ? ($sysByCode[$account->code] ?? null) : null;
            $sd = $sys ? (string) $sys['debit_total'] : '0.00';
            $sc = $sys ? (string) $sys['credit_total'] : '0.00';
            if ($account) $seenCodes[$account->code] = true;

            // Variance on the net (debit - credit) so a side flip is visible.
            $legacyNet = Money::sub($ld, $lc);
            $sysNet    = Money::sub(Money::round2($sd), Money::round2($sc));
            $variance  = Money::sub($legacyNet, $sysNet);

            $rows[] = [
                'account_code' => $account?->code ?? '(unknown)',
                'account_name' => $account?->name ?? '(unresolved account)',
                'legacy_debit'  => $ld,
                'legacy_credit' => $lc,
                'system_debit'  => Money::round2($sd),
                'system_credit' => Money::round2($sc),
                'variance'      => $variance,
            ];
        }

        // System accounts with balances not present in the legacy TB → surface them.
        foreach ($system['accounts'] as $a) {
            if (isset($seenCodes[$a['code']])) continue;
            $sd = Money::round2((string) $a['debit_total']);
            $sc = Money::round2((string) $a['credit_total']);
            if (Money::cmp($sd, '0') === 0 && Money::cmp($sc, '0') === 0) continue;
            $rows[] = [
                'account_code' => $a['code'],
                'account_name' => $a['name'],
                'legacy_debit'  => '0.00',
                'legacy_credit' => '0.00',
                'system_debit'  => $sd,
                'system_credit' => $sc,
                'variance'      => Money::sub('0.00', Money::sub($sd, $sc)),
            ];
        }

        $balanced = true;
        foreach ($rows as $r) {
            if (Money::cmp($r['variance'], '0') !== 0) { $balanced = false; break; }
        }

        return [
            'balanced'            => $balanced,
            'rows'                => $rows,
            'legacy_total_debit'  => $legacyDebit,
            'legacy_total_credit' => $legacyCredit,
            'system_total_debit'  => (string) $system['totals']['debit'],
            'system_total_credit' => (string) $system['totals']['credit'],
        ];
    }

    private function resolveAccount(mixed $accountId): ?Account
    {
        if ($accountId === null) return null;
        $id = is_numeric($accountId)
            ? (int) $accountId
            : HashIdFilter::decode((string) $accountId, Account::class);
        return $id ? Account::query()->find($id) : null;
    }
}
