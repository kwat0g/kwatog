<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
use App\Modules\Accounting\Services\AccountingAccountPolicyService;
use App\Modules\Accounting\Services\JournalEntryService;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Posts an accepted (or partially-accepted) GRN to the General Ledger.
 *
 * For each accepted line we DR the inventory account routed by item_type
 * (raw materials → 1200, finished goods → 1210, packaging → 1220, spare parts
 * → 1230) and CR a single 2110 Goods Received Not Invoiced offset for the
 * total accepted value. The companion Bill (in BillService::create) later
 * debits 2110 and credits Accounts Payable, closing the GRNI loop.
 *
 * Idempotent and cumulative: the first accepted quantity posts the initial
 * GRNI entry; later cumulative acceptance posts only the delta. The GRN's
 * journal_entry_id remains the primary entry for backwards compatibility,
 * while all entries are linked through the same document reference.
 *
 * Feature flag: gated behind `modules.accounting`. When the accounting
 * module is disabled (early sprints, or a company that hasn't activated it)
 * the post is skipped and the GRN is left untouched. A backfill command can
 * post the JE later when the module is turned on.
 */
class GrnGlPostingService
{
    public function __construct(
        private readonly JournalEntryService $journals,
        private readonly AccountingAccountPolicyService $accountPolicies,
        private readonly SettingsService $settings,
    ) {}

    /**
     * Post the GRN's accepted-line value to the GL. Returns the JE id, or
     * null when skipped (flag off, schema missing, or nothing accepted).
     */
    public function post(GoodsReceiptNote $grn): ?int
    {
        return DB::transaction(fn () => $this->postLocked($grn));
    }

    private function postLocked(GoodsReceiptNote $grn): ?int
    {
        if ($grn->status !== GrnStatus::Accepted && $grn->status !== GrnStatus::PartialAccepted) {
            throw new BusinessRuleException('Only accepted GRNs can be posted to the GL.');
        }

        // Serialize cumulative posting with acceptance updates. This also
        // makes a retried queue/job invocation observe the same posted total.
        $grn = GoodsReceiptNote::query()->lockForUpdate()->findOrFail($grn->id);
        if ($grn->status !== GrnStatus::Accepted && $grn->status !== GrnStatus::PartialAccepted) {
            throw new BusinessRuleException('Only accepted GRNs can be posted to the GL.');
        }

        $accountingEnabled = $this->settings->requiredBool('modules.accounting');
        if (! $accountingEnabled) {
            Log::info('GrnGlPostingService: accounting module disabled; skipping GL post', [
                'grn_id' => $grn->id,
            ]);
            return null;
        }

        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('accounts')) {
            Log::warning('GrnGlPostingService: journal_entries / accounts table missing; skipping');
            return null;
        }

        // Aggregate the current cumulative accepted value by inventory account
        // code. A later partial acceptance is reconciled against the already
        // posted journal entries below, so it never reposts the first delta.
        // Base material remains GRNI-backed; snapshotted landed cost is credited
        // to its own clearing liability instead of inflating the supplier bill.
        $grn->loadMissing('items');
        /** @var array<string, string> $byAccount */
        $byAccount = [];
        /** @var array<string, int> $accountIds */
        $accountIds = [];
        $baseTotal = Money::zero();
        $landedTotal = Money::zero();

        foreach ($grn->items as $row) {
            $accepted = Money::round2((string) $row->quantity_accepted);
            if (Money::isZero($accepted)) {
                continue;
            }
            $baseValue = Money::round2(bcmul($accepted, (string) $row->unit_cost, 6));
            $received = (string) $row->quantity_received;
            $fullLanded = Money::round2((string) ($row->landed_cost_total ?? '0'));
            $landedValue = bccomp($received, '0', 8) > 0
                ? Money::round2(bcmul($fullLanded, bcdiv($accepted, $received, 12), 6))
                : Money::zero();
            $value = Money::add($baseValue, $landedValue);

            // withTrashed(): archiving an item in inventory-master must not turn
            // a legitimate acceptance into an unhandled ModelNotFoundException
            // (a 500 with no actionable message). The routed account is derived
            // from item_type, which archiving does not change, so the accounts
            // and the amount are exactly what they would have been beforehand.
            $item = Item::withTrashed()->whereKey($row->item_id)->firstOrFail();
            $code = $this->inventoryAccountCode($item);
            try {
                $accountIds[$code] = $this->inventoryAccountId($item);
            } catch (RuntimeException $e) {
                throw new BusinessRuleException("Inventory account {$code} missing from chart of accounts.", 0, $e);
            }

            $byAccount[$code] = isset($byAccount[$code])
                ? Money::add($byAccount[$code], $value)
                : $value;
            $baseTotal = Money::add($baseTotal, $baseValue);
            $landedTotal = Money::add($landedTotal, $landedValue);
        }

        if (Money::isZero(Money::add($baseTotal, $landedTotal)) || empty($byAccount)) {
            Log::info('GrnGlPostingService: no accepted value to post', [
                'grn_id' => $grn->id,
            ]);
            return $grn->journal_entry_id ? (int) $grn->journal_entry_id : null;
        }

        // Lookup account ids (DR rows + GRNI).
        $grniCode = $this->settings->requiredString('accounting.accounts.grni_code');
        try {
            $accountIds[$grniCode] = $this->accountPolicies
                ->controlAccountIdForSetting('accounting.accounts.grni_code');
        } catch (RuntimeException $e) {
            throw new RuntimeException("GRNI clearing account {$grniCode} missing from chart of accounts.", 0, $e);
        }

        $landedCode = null;
        if (Money::gt($landedTotal, '0')) {
            $landedCode = $this->settings->requiredString('accounting.accounts.landed_cost_clearing_code');
            try {
                $accountIds[$landedCode] = $this->accountPolicies
                    ->controlAccountIdForSetting('accounting.accounts.landed_cost_clearing_code');
            } catch (RuntimeException $e) {
                throw new RuntimeException("Landed cost clearing account {$landedCode} missing from chart of accounts.", 0, $e);
            }
        }

        $codes = array_unique(array_merge(array_keys($byAccount), [$grniCode], $landedCode ? [$landedCode] : []));
        $postedByCode = DB::table('journal_entry_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('entry.reference_type', 'goods_receipt_note')
            ->where('entry.reference_id', $grn->id)
            ->where('entry.status', 'posted')
            ->whereIn('account.code', $codes)
            ->select([
                'account.code',
                DB::raw('COALESCE(SUM(line.debit), 0) as debit'),
                DB::raw('COALESCE(SUM(line.credit), 0) as credit'),
            ])
            ->groupBy('account.code')
            ->get()
            ->keyBy('code');

        /** @var array<string,string> $deltaByAccount */
        $deltaByAccount = [];
        $deltaDebitTotal = Money::zero();
        foreach ($byAccount as $code => $amount) {
            $posted = (string) ($postedByCode->get($code)?->debit ?? '0');
            if (Money::lt($amount, $posted)) {
                throw new BusinessRuleException(
                    "Accepted GRN value for account {$code} cannot decrease below the amount already posted."
                );
            }
            $delta = Money::sub($amount, $posted);
            if (! Money::isZero($delta)) {
                $deltaByAccount[$code] = $delta;
            }
            $deltaDebitTotal = Money::add($deltaDebitTotal, $delta);
        }

        $postedGrni = (string) ($postedByCode->get($grniCode)?->credit ?? '0');
        if (Money::lt($baseTotal, $postedGrni)) {
            throw new BusinessRuleException('Accepted GRN value cannot decrease below the GRNI amount already posted.');
        }
        $deltaGrni = Money::sub($baseTotal, $postedGrni);

        $postedLanded = $landedCode
            ? (string) ($postedByCode->get($landedCode)?->credit ?? '0')
            : '0.00';
        if (Money::lt($landedTotal, $postedLanded)) {
            throw new BusinessRuleException('Capitalized landed cost cannot decrease below the amount already posted.');
        }
        $deltaLanded = Money::sub($landedTotal, $postedLanded);
        $deltaCredits = Money::add($deltaGrni, $deltaLanded);

        if (Money::cmp($deltaDebitTotal, $deltaCredits) !== 0) {
            throw new RuntimeException('GRN inventory, GRNI, and landed-cost deltas are out of balance.');
        }

        if (Money::isZero($deltaCredits) && $deltaByAccount === []) {
            if ($grn->journal_entry_id) {
                return (int) $grn->journal_entry_id;
            }

            return DB::table('journal_entries')
                ->where('reference_type', 'goods_receipt_note')
                ->where('reference_id', $grn->id)
                ->where('status', 'posted')
                ->orderBy('id')
                ->value('id');
        }

        if (Money::isZero($deltaCredits) || $deltaByAccount === []) {
            // An internal accounting invariant, not a rule the user broke: the
            // debit and credit deltas this service just computed disagree with
            // each other. There is nothing to correct on the GRN form.
            throw new RuntimeException('GRN inventory and GRNI deltas are out of balance.');
        }

        $lines = [];
        foreach ($deltaByAccount as $code => $amount) {
            if (! isset($accountIds[$code])) {
                Log::error('GrnGlPostingService: configured inventory account missing', [
                    'grn_id' => $grn->id,
                    'missing_code' => $code,
                ]);
                throw new BusinessRuleException("Inventory account {$code} missing from chart of accounts.");
            }
            $lines[] = [
                'account_id'  => $accountIds[$code],
                'debit'       => $amount,
                'credit'      => '0.00',
                'description' => "GRN {$grn->grn_number} — inventory receipt",
            ];
        }
        $lines[] = [
            'account_id'  => $accountIds[$grniCode],
            'debit'       => '0.00',
            'credit'      => $deltaGrni,
            'description' => "GRN {$grn->grn_number} — GRNI clearing",
        ];
        if (! Money::isZero($deltaLanded)) {
            $lines[] = [
                'account_id'  => $accountIds[$landedCode],
                'debit'       => '0.00',
                'credit'      => $deltaLanded,
                'description' => "GRN {$grn->grn_number} — landed cost clearing",
            ];
        }

        return DB::transaction(function () use ($grn, $lines) {
            $je = $this->journals->create([
                'date'           => $grn->received_date instanceof \DateTimeInterface
                    ? $grn->received_date->format('Y-m-d')
                    : (string) $grn->received_date,
                'description'    => sprintf(
                    'GRN %s — %s',
                    $grn->grn_number,
                    $grn->journal_entry_id ? 'incremental inventory acceptance' : 'inventory receipt',
                ),
                'reference_type' => 'goods_receipt_note',
                'reference_id'   => $grn->id,
                'lines'          => $lines,
            ]);

            // Promote the system-generated draft through the canonical
            // accounting lifecycle; do not mutate journal_entries directly.
            $this->journals->postSystem($je);

            if (! $grn->journal_entry_id) {
                $grn->journal_entry_id = $je->id;
                $grn->save();
            }

            return (int) $je->id;
        });
    }

    /** Resolve the configured inventory account for an item type. */
    public function inventoryAccountCode(Item $item): string
    {
        return $this->settings->requiredString($this->inventoryAccountSettingKey($item));
    }

    public function inventoryAccountId(Item $item): int
    {
        return $this->accountPolicies->controlAccountIdForSetting($this->inventoryAccountSettingKey($item));
    }

    private function inventoryAccountSettingKey(Item $item): string
    {
        $type = $item->item_type instanceof ItemType ? $item->item_type->value : (string) $item->item_type;

        return match ($type) {
            ItemType::RawMaterial->value  => 'accounting.accounts.inventory_raw_material_code',
            ItemType::FinishedGood->value => 'accounting.accounts.inventory_finished_goods_code',
            ItemType::Packaging->value    => 'accounting.accounts.inventory_packaging_code',
            ItemType::SparePart->value    => 'accounting.accounts.inventory_spare_parts_code',
            default => throw new BusinessRuleException("No inventory account configured for item type {$type}"),
        };
    }
}
