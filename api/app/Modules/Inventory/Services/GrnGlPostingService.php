<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Services;

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
 * Idempotent: bails when goods_receipt_notes.journal_entry_id is already set.
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
        if ($grn->status !== GrnStatus::Accepted && $grn->status !== GrnStatus::PartialAccepted) {
            throw new RuntimeException('Only accepted GRNs can be posted to the GL.');
        }

        if ($grn->journal_entry_id) {
            return (int) $grn->journal_entry_id;
        }

        $accountingEnabled = (bool) $this->settings->get('modules.accounting', false);
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

        // Aggregate accepted value by inventory account code.
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

            $item = Item::query()->whereKey($row->item_id)->first();
            $code = $item ? $this->inventoryAccountCode($item) : '1200';

            $byAccount[$code] = isset($byAccount[$code])
                ? Money::add($byAccount[$code], $value)
                : $value;
            $total = Money::add($total, $value);
        }

        if (Money::isZero($total) || empty($byAccount)) {
            Log::info('GrnGlPostingService: no accepted value to post', [
                'grn_id' => $grn->id,
            ]);
            return null;
        }

        // Lookup account ids (DR rows + GRNI).
        $codes = array_unique(array_merge(array_keys($byAccount), ['2110']));
        $accountIds = DB::table('accounts')->whereIn('code', $codes)->pluck('id', 'code');

        if (! isset($accountIds['2110'])) {
            Log::error('GrnGlPostingService: GRNI account 2110 not found in COA', [
                'grn_id' => $grn->id,
            ]);
            throw new RuntimeException('GRNI clearing account 2110 missing from chart of accounts.');
        }

        $lines = [];
        foreach ($byAccount as $code => $amount) {
            if (! isset($accountIds[$code])) {
                Log::warning('GrnGlPostingService: inventory account missing; defaulting to 1200', [
                    'grn_id' => $grn->id,
                    'missing_code' => $code,
                ]);
                $fallback = $accountIds['1200'] ?? null;
                if (! $fallback) {
                    throw new RuntimeException("Inventory account {$code} missing and 1200 fallback also missing.");
                }
                $lines[] = [
                    'account_id'  => $fallback,
                    'debit'       => $amount,
                    'credit'      => '0.00',
                    'description' => "GRN {$grn->grn_number} — inventory receipt",
                ];
                continue;
            }
            $lines[] = [
                'account_id'  => $accountIds[$code],
                'debit'       => $amount,
                'credit'      => '0.00',
                'description' => "GRN {$grn->grn_number} — inventory receipt",
            ];
        }
        $lines[] = [
            'account_id'  => $accountIds['2110'],
            'debit'       => '0.00',
            'credit'      => $total,
            'description' => "GRN {$grn->grn_number} — GRNI clearing",
        ];

        return DB::transaction(function () use ($grn, $lines) {
            $je = $this->journals->create([
                'date'           => $grn->received_date instanceof \DateTimeInterface
                    ? $grn->received_date->format('Y-m-d')
                    : (string) $grn->received_date,
                'description'    => sprintf('GRN %s — Inventory receipt', $grn->grn_number),
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

            $grn->journal_entry_id = $je->id;
            $grn->save();

            return (int) $je->id;
        });
    }

    /**
     * Pragmatic switch — keep this in sync with the COA. We deliberately
     * avoid a configurable mapping table; the four item_type values map
     * 1:1 onto the four inventory accounts.
     */
    private function inventoryAccountCode(Item $item): string
    {
        $type = $item->item_type instanceof ItemType ? $item->item_type->value : (string) $item->item_type;

        return match ($type) {
            ItemType::RawMaterial->value  => '1200',
            ItemType::FinishedGood->value => '1210',
            ItemType::Packaging->value    => '1220',
            ItemType::SparePart->value    => '1230',
            default => '1200',
        };
    }
}
