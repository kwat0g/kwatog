<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\BudgetLineItem;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\BudgetTransferService;
use App\Modules\Auth\Models\User;
use App\Modules\HR\Models\Department;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Models\SupplierQuote;
use App\Modules\Purchasing\Services\RequestForQuoteService;
use App\Modules\Purchasing\Services\SupplierQuoteService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Panel-demo showcase: one live RFQ with two submitted quotes ready to
 * award, plus one budget in each demoable state (draft, submitted, and a
 * pending line-to-line transfer).
 *
 * Every block is guarded and skips when its records already exist, so
 * re-runs are safe. Quote submission bypasses the formal-quotation-PDF gate
 * via forceFill (a supplier uploads that PDF in the portal in real life);
 * everything else goes through the real services.
 */
class DemoRfqBudgetSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedDemoRfq();
        $this->seedDemoBudgetFlow();

        $this->command?->info('  Demo RFQ (quotable) + demo budget flow ready.');
    }

    private function seedDemoRfq(): void
    {
        $buyer = User::where('email', 'purchasing@ogami.test')->firstOrFail();

        $rfq = RequestForQuote::query()->where('title', 'like', 'DEMO %')->orderBy('id')->first();
        if (! $rfq) {
            $rfq = $this->createDemoRfq($buyer);
            if (! $rfq) {
                return;
            }
        }
        $this->seedDemoQuotes($buyer, $rfq);

        $this->command?->info("  Demo RFQ {$rfq->rfq_number} open with submitted quotes.");
    }

    /**
     * @return \App\Modules\Purchasing\Models\RequestForQuote|null
     */
    private function createDemoRfq(User $buyer)
    {
        $deptHead = User::where('email', 'depthead@ogami.test')->firstOrFail();
        $item = Item::query()->where('is_active', true)->orderBy('id')->firstOrFail();
        $vendors = \App\Modules\Accounting\Models\Vendor::query()
            ->where('is_active', true)->orderBy('id')->take(2)->get();
        if ($vendors->count() < 2) {
            $this->command?->warn('  Demo RFQ skipped: need two active vendors.');

            return null;
        }

        // Factory + forceFill, the demo-seeder convention: an approved,
        // RFQ-sourced PR with an item-linked line, ready for sourcing.
        $pr = PurchaseRequest::factory()->create([
            'requested_by' => $deptHead->id,
            'department_id' => $deptHead->employee?->department_id
                ?? Department::query()->where('code', 'PROD')->value('id'),
        ]);
        $pr->forceFill([
            'status' => \App\Modules\Purchasing\Enums\PurchaseRequestStatus::Approved,
            'po_conversion_status' => \App\Modules\Purchasing\Enums\PurchaseRequestConversionStatus::SourcingPending,
            'sourcing_method' => \App\Modules\Purchasing\Enums\PurchaseRequestSourcingMethod::Rfq,
        ])->save();
        \App\Modules\Purchasing\Models\PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => $item->name,
            'quantity' => '10.00',
            'unit' => $item->unit_of_measure ?? 'pcs',
            'estimated_unit_price' => '1000.00',
        ]);

        return app(RequestForQuoteService::class)->createFromPurchaseRequest($pr->fresh(), [
            'title' => 'DEMO Resin RFQ — quotable, ready to award',
            'instructions' => 'Defense demo RFQ. Two invited suppliers, both quoted.',
            'closes_at' => now()->addDays(14)->toDateTimeString(),
            'invitations' => $vendors->map(fn ($vendor): array => [
                'vendor_id' => $vendor->id,
                'exception_reason' => 'Defense demo invitation.',
            ])->all(),
            'publish' => true,
        ], $buyer);
    }

    private function seedDemoQuotes(User $buyer, RequestForQuote $rfq): void
    {
        $vendors = \App\Modules\Accounting\Models\Vendor::query()
            ->where('is_active', true)->orderBy('id')->take(2)->get();
        $quotes = app(SupplierQuoteService::class);
        $prices = ['950.00', '1020.00'];
        foreach ($vendors as $index => $vendor) {
            $already = SupplierQuote::query()
                ->where('request_for_quote_id', $rfq->id)
                ->where('vendor_id', $vendor->id)
                ->where('status', \App\Modules\Purchasing\Enums\SupplierQuoteStatus::Submitted)
                ->exists();
            if ($already) {
                continue;
            }
            $quote = $quotes->save($rfq, (int) $vendor->id, [
                'vat_treatment' => 'exclusive',
                'freight_amount' => '0',
                'quote_valid_until' => now()->addDays(30)->toDateString(),
                'payment_terms' => '30 days',
                'notes' => 'Defense demo quote.',
                'items' => $rfq->items->map(fn ($line): array => [
                    'request_for_quote_item_id' => $line->id,
                    'response_status' => 'quoted',
                    'offered_quantity' => (string) $line->quantity,
                    'unit_price' => $prices[$index],
                    'lead_time_days' => 7,
                ])->all(),
            ], false, null, $buyer);
            // Demo bypass: the formal quotation PDF is uploaded by the
            // supplier in the portal in real life; the seeder marks the
            // buyer-captured quote submitted so comparison/award demo live.
            $quote->forceFill([
                'status' => \App\Modules\Purchasing\Enums\SupplierQuoteStatus::Submitted,
                'submitted_at' => now(),
            ])->save();
            $quote->invitation()->update(['status' => \App\Modules\Purchasing\Enums\RfqInvitationStatus::Submitted]);
        }

        $this->command?->info("  Demo RFQ {$rfq->rfq_number} open with two submitted quotes.");
    }

    private function seedDemoBudgetFlow(): void
    {
        $fiscalYearId = DB::table('fiscal_years')->where('status', 'active')->orderByDesc('year')->value('id');
        if (! $fiscalYearId) {
            $this->command?->warn('  Demo budget flow skipped: no active fiscal year.');

            return;
        }
        $finance = User::where('email', 'finance@ogami.test')->firstOrFail();
        $budgets = app(BudgetService::class);

        // Draft budget: the create/submit click path.
        $draft = $this->demoBudget($budgets, (int) $fiscalYearId, 'DEMO Draft Budget — submit me');
        // Submitted budget: the approve/return click path.
        $submitted = $this->demoBudget($budgets, (int) $fiscalYearId, 'DEMO Submitted Budget — approve me');
        if ($submitted && $submitted->status === 'draft') {
            $budgets->submit($submitted, $finance->id);
        }

        // Pending transfer on live Production lines: the approve/reject path.
        $transferService = app(BudgetTransferService::class);
        if (! \App\Modules\Accounting\Models\BudgetTransfer::query()->where('reason', 'like', 'DEMO %')->exists()) {
            $lines = BudgetLineItem::query()
                ->join('budgets as b', 'b.id', '=', 'budget_line_items.budget_id')
                ->where('b.fiscal_year_id', $fiscalYearId)
                ->whereIn('b.status', ['approved', 'active'])
                ->orderBy('budget_line_items.id')
                ->select('budget_line_items.*')
                ->take(2)->get();
            if ($lines->count() === 2 && $lines[0]->budget_id === $lines[1]->budget_id) {
                $transferService->request([
                    'from_line_item_id' => $lines[0]->id,
                    'to_line_item_id' => $lines[1]->id,
                    'month' => 'feb',
                    'amount' => '25.00',
                    'reason' => 'DEMO transfer — approve or reject me.',
                ], $finance->id);
            }
        }

        $this->command?->info('  Demo budget flow: draft + submitted + pending transfer present.');
    }

    /**
     * @return \App\Modules\Accounting\Models\Budget|null
     */
    private function demoBudget(BudgetService $budgets, int $fiscalYearId, string $name)
    {
        $existing = \App\Modules\Accounting\Models\Budget::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }
        $account = Account::query()
            ->where('type', 'expense')->where('is_active', true)
            ->whereDoesntHave('children')
            ->orderBy('id')->first();
        if (! $account) {
            return null;
        }

        return $budgets->create([
            'fiscal_year_id' => $fiscalYearId,
            'department_id' => Department::query()->where('code', 'PROD')->value('id'),
            'budget_type' => 'operating',
            'name' => $name,
        ], [['account_id' => $account->id, 'jan' => '12000.00']]);
    }
}
