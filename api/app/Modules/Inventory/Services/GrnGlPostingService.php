<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SettingsService;
use App\Common\Support\Money;
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
        $grn->loadMissing('items');
        /** @var array<string, string> $byAccount */
        $byAccount = [];
        $total = '0.00';

        foreach ($grn->items as $row) {
            $accepted = Money::round2((string) $row->quantity_accepted);
            if (Money::isZero($accepted)) {
                continue;
            }
            $unitCost = (string) $row->unit_cost;
            $value    = Money::round2(bcmul($accepted, $unitCost, 6));

            $item = Item::query()->whereKey($row->item_id)->firstOrFail();
            $code = $this->inventoryAccountCode($item);

            $byAccount[$code] = isset($byAccount[$code])
                ? Money::add($byAccount[$code], $value)
                : $value;
            $total = Money::add($total, $value);
        }

        if (Money::isZero($total) || empty($byAccount)) {
            Log::info('GrnGlPostingService: no accepted value to post', [
                'grn_id' => $grn->id,
            ]);
            return $grn->journal_entry_id ? (int) $grn->journal_entry_id : null;
        }

        // Lookup account ids (DR rows + GRNI).
        $grniCode = $this->settings->requiredString('accounting.accounts.grni_code');
        $codes = array_unique(array_merge(array_keys($byAccount), [$grniCode]));
        $accountIds = DB::table('accounts')->whereIn('code', $codes)->pluck('id', 'code');

        if (! isset($accountIds[$grniCode])) {
            Log::error('GrnGlPostingService: configured GRNI account not found in COA', [
                'grn_id' => $grn->id,
            ]);
            throw new RuntimeException("GRNI clearing account {$grniCode} missing from chart of accounts.");
        }

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
        }

        $postedGrni = (string) ($postedByCode->get($grniCode)?->credit ?? '0');
        if (Money::lt($total, $postedGrni)) {
            throw new BusinessRuleException('Accepted GRN value cannot decrease below the GRNI amount already posted.');
        }
        $deltaTotal = Money::sub($total, $postedGrni);

        if (Money::isZero($deltaTotal) && $deltaByAccount === []) {
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

        if (Money::isZero($deltaTotal) || $deltaByAccount === []) {
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
            'credit'      => $deltaTotal,
            'description' => "GRN {$grn->grn_number} — GRNI clearing",
        ];

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

            // Promote draft → posted. We pass null for the system user; the
            // service signature accepts ?User and only stamps posted_by from
            // the supplied user, which is fine for a system-generated post.
            // JournalEntryService::post() requires a non-null User so we go
            // through DB directly — mirrors PayrollGlPostingService's
            // shortcut (it inserts as 'posted' in one shot).
            DB::table('journal_entries')->where('id', $je->id)->update([
                'status'    => 'posted',
                'posted_at' => now(),
                'updated_at' => now(),
            ]);

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
        $type = $item->item_type instanceof ItemType ? $item->item_type->value : (string) $item->item_type;

        return match ($type) {
            ItemType::RawMaterial->value  => $this->settings->requiredString('accounting.accounts.inventory_raw_material_code'),
            ItemType::FinishedGood->value => $this->settings->requiredString('accounting.accounts.inventory_finished_goods_code'),
            ItemType::Packaging->value    => $this->settings->requiredString('accounting.accounts.inventory_packaging_code'),
            ItemType::SparePart->value    => $this->settings->requiredString('accounting.accounts.inventory_spare_parts_code'),
            default => throw new BusinessRuleException("No inventory account configured for item type {$type}"),
        };
    }
}
