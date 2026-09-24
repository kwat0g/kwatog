<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Simplified RFQ process (docs/SUPPLIER-RFQ-BIDDING-PLAN.md).
 *
 * - One quote per supplier per RFQ (versions collapsed onto the evaluated row).
 * - One winning award per RFQ line.
 * - Quote VAT is a declared treatment (exclusive / inclusive / none) plus one
 *   freight amount; line-level charges fold into that freight.
 * - Addenda, quote reconfirmation, quote-stage QC review, RFQ budget warning
 *   and five of seven RFQ permissions are retired.
 *
 * Timestamp-named because it alters tables created by the 2026_09_16_* RFQ
 * migrations. Data is reconciled before any constraint is added, and a row
 * that cannot fit the new rules stops the migration instead of being dropped.
 */
return new class extends Migration
{
    private const RETIRED_TO_MANAGE = ['purchasing.rfq.create', 'purchasing.rfq.publish', 'purchasing.rfq.award'];

    private const RETIRED_TO_VIEW = ['purchasing.rfq.evaluate', 'purchasing.rfq.quality_review'];

    public function up(): void
    {
        $this->collapseQuoteVersions();
        $this->foldLineCharges();
        $this->assertOneAwardPerLine();

        DB::table('request_for_quotes')->where('status', 'under_evaluation')->update(['status' => 'closed']);
        DB::table('request_for_quotes')->where('status', 'partially_awarded')->update(['status' => 'awarded']);
        DB::table('request_for_quotes')->where('status', 'no_award')->update([
            'status' => 'cancelled',
            'cancellation_reason' => DB::raw("COALESCE(cancellation_reason, no_award_reason, 'No award was made.')"),
        ]);
        DB::table('request_for_quote_invitations')->where('status', 'withdrawn')->update(['status' => 'viewed']);

        DB::statement('DROP INDEX IF EXISTS supplier_quotes_current_submitted_unique');
        Schema::table('supplier_quotes', function (Blueprint $table): void {
            $table->dropIndex(['request_for_quote_id', 'vendor_id', 'is_current']);
            $table->dropUnique('supplier_quote_version_unique');
            $table->dropColumn(['version', 'is_current', 'withdrawn_at', 'withdrawal_reason', 'vat_inclusive', 'other_charges']);
            $table->unique(['request_for_quote_id', 'vendor_id'], 'supplier_quote_vendor_unique');
        });

        Schema::table('supplier_quote_items', function (Blueprint $table): void {
            $table->dropColumn([
                'line_vat_amount', 'line_freight_amount', 'line_other_charges',
                'minimum_order_quantity', 'order_quantity_multiple', 'compliance_status', 'compliance_notes',
            ]);
        });

        Schema::table('rfq_awards', function (Blueprint $table): void {
            $table->dropIndex(['request_for_quote_item_id', 'status']);
            $table->dropColumn(['status', 'single_response_justification']);
            $table->unique('request_for_quote_item_id', 'rfq_awards_line_unique');
        });

        Schema::table('request_for_quote_items', function (Blueprint $table): void {
            $table->dropColumn(['allow_partial_quantity', 'allow_substitute']);
        });

        Schema::table('request_for_quotes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('budget_acknowledged_by');
            $table->dropColumn([
                'evaluation_started_at', 'no_award_reason', 'currency',
                'budget_warning_level', 'budget_warning_message', 'budget_acknowledged_at',
            ]);
        });

        Schema::dropIfExists('rfq_quote_reconfirmations');
        Schema::dropIfExists('rfq_addenda');

        $this->retirePermissions();
    }

    public function down(): void
    {
        // The collapsed quote versions, folded charges and retired addenda /
        // reconfirmation rows cannot be reconstructed. Restore the schema shape
        // only, so an older checkout can still boot against this database.
        Schema::create('rfq_addenda', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_for_quote_id')->constrained('request_for_quotes')->cascadeOnDelete();
            $table->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('title', 200);
            $table->text('body');
            $table->boolean('material_change')->default(false);
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['request_for_quote_id', 'sequence'], 'rfq_addenda_sequence_unique');
        });
        Schema::create('rfq_quote_reconfirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('supplier_quote_id')->constrained('supplier_quotes')->restrictOnDelete();
            $table->foreignId('supplier_portal_user_id')->nullable()->constrained('supplier_portal_users')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->json('terms_snapshot');
            $table->text('reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique('purchase_order_id', 'rfq_reconfirmation_po_unique');
        });
        Schema::table('request_for_quotes', function (Blueprint $table): void {
            $table->char('currency', 3)->default('PHP');
            $table->timestamp('evaluation_started_at')->nullable();
            $table->text('no_award_reason')->nullable();
            $table->string('budget_warning_level', 30)->nullable();
            $table->text('budget_warning_message')->nullable();
            $table->foreignId('budget_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('budget_acknowledged_at')->nullable();
        });
        Schema::table('request_for_quote_items', function (Blueprint $table): void {
            $table->boolean('allow_partial_quantity')->default(true);
            $table->boolean('allow_substitute')->default(false);
        });
        Schema::table('rfq_awards', function (Blueprint $table): void {
            $table->dropUnique('rfq_awards_line_unique');
            $table->text('single_response_justification')->nullable();
            $table->string('status', 20)->default('awarded');
            $table->index(['request_for_quote_item_id', 'status']);
        });
        Schema::table('supplier_quote_items', function (Blueprint $table): void {
            $table->decimal('line_vat_amount', 15, 2)->default(0);
            $table->decimal('line_freight_amount', 15, 2)->default(0);
            $table->decimal('line_other_charges', 15, 2)->default(0);
            $table->decimal('minimum_order_quantity', 15, 4)->nullable();
            $table->decimal('order_quantity_multiple', 15, 4)->nullable();
            $table->string('compliance_status', 20)->default('pending');
            $table->text('compliance_notes')->nullable();
        });
        Schema::table('supplier_quotes', function (Blueprint $table): void {
            $table->dropUnique('supplier_quote_vendor_unique');
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_current')->default(true);
            $table->timestamp('withdrawn_at')->nullable();
            $table->text('withdrawal_reason')->nullable();
            $table->boolean('vat_inclusive')->default(false);
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->unique(['request_for_quote_id', 'vendor_id', 'version'], 'supplier_quote_version_unique');
            $table->index(['request_for_quote_id', 'vendor_id', 'is_current']);
        });
        DB::table('supplier_quotes')->where('vat_treatment', 'inclusive')->update(['vat_inclusive' => true]);
        Schema::table('supplier_quotes', function (Blueprint $table): void {
            $table->dropColumn('vat_treatment');
        });
    }

    /**
     * Keep one quote per (rfq, vendor): the evaluated one (awarded /
     * not_awarded / submitted, newest version first), else the newest draft.
     * Documents, awards and PO traces on the dropped versions are re-pointed to
     * the kept row before the dropped rows go.
     */
    private function collapseQuoteVersions(): void
    {
        Schema::table('supplier_quotes', function (Blueprint $table): void {
            $table->string('vat_treatment', 10)->default('exclusive')->after('status');
        });

        $groups = DB::table('supplier_quotes')
            ->select('request_for_quote_id', 'vendor_id')
            ->groupBy('request_for_quote_id', 'vendor_id')
            ->get();
        foreach ($groups as $group) {
            $rows = DB::table('supplier_quotes')
                ->where('request_for_quote_id', $group->request_for_quote_id)
                ->where('vendor_id', $group->vendor_id)
                ->get();
            $rank = static fn (object $row): int => match ($row->status) {
                'awarded', 'not_awarded' => 3,
                'submitted' => $row->is_current ? 2 : 1,
                default => 0,
            };
            $keep = $rows->sort(static fn (object $a, object $b): int => [$rank($b), $b->version] <=> [$rank($a), $a->version])->first();
            $dropIds = $rows->pluck('id')->reject(static fn ($id): bool => (int) $id === (int) $keep->id)->values()->all();
            if ($dropIds !== []) {
                DB::table('rfq_documents')->whereIn('supplier_quote_id', $dropIds)->update(['supplier_quote_id' => $keep->id]);
                if (DB::table('rfq_awards')->whereIn('supplier_quote_id', $dropIds)->exists()) {
                    throw new RuntimeException("RFQ {$group->request_for_quote_id}: an award points at a superseded quote version for vendor {$group->vendor_id}; reconcile it before migrating.");
                }
                DB::table('purchase_order_items')->whereIn('supplier_quote_version_id', $dropIds)->update(['supplier_quote_version_id' => $keep->id]);
                DB::table('supplier_quotes')->whereIn('id', $dropIds)->delete();
            }
            $status = in_array($keep->status, ['draft', 'submitted', 'awarded', 'not_awarded'], true) ? $keep->status : 'draft';
            $treatment = $keep->vat_inclusive
                ? 'inclusive'
                : (bccomp((string) $keep->vat_amount, '0', 2) === 0 && bccomp((string) $keep->total_delivered_cost, '0', 2) > 0 ? 'none' : 'exclusive');
            DB::table('supplier_quotes')->where('id', $keep->id)->update([
                'status' => $status,
                'submitted_at' => $status === 'draft' ? null : $keep->submitted_at,
                'vat_treatment' => $treatment,
            ]);
        }
    }

    /**
     * Line VAT already sits in the quote's vat_amount. Line freight and other
     * charges fold into the single quote freight; header other charges too.
     * Line totals become goods only (qty × price).
     */
    private function foldLineCharges(): void
    {
        foreach (DB::table('supplier_quotes')->get(['id', 'freight_amount', 'other_charges']) as $quote) {
            $lineCharges = DB::table('supplier_quote_items')
                ->where('supplier_quote_id', $quote->id)
                ->selectRaw('COALESCE(SUM(line_freight_amount + line_other_charges), 0) AS charges')
                ->value('charges');
            DB::table('supplier_quotes')->where('id', $quote->id)->update([
                'freight_amount' => bcadd(bcadd((string) $quote->freight_amount, (string) $quote->other_charges, 2), (string) $lineCharges, 2),
            ]);
        }
        DB::table('supplier_quote_items')->update([
            'line_total_delivered_cost' => DB::raw('ROUND(COALESCE(offered_quantity, 0) * COALESCE(unit_price, 0), 2)'),
        ]);
    }

    private function assertOneAwardPerLine(): void
    {
        $split = DB::table('rfq_awards')
            ->where('status', 'awarded')
            ->select('request_for_quote_item_id')
            ->groupBy('request_for_quote_item_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('request_for_quote_item_id');
        if ($split->isNotEmpty()) {
            throw new RuntimeException('RFQ lines awarded to more than one supplier cannot move to one-winner-per-line: request_for_quote_item_id '.$split->implode(', '));
        }
        DB::table('rfq_awards')->where('status', '!=', 'awarded')->delete();
    }

    private function retirePermissions(): void
    {
        $grantFor = function (array $retired, string $target): void {
            $retiredIds = DB::table('permissions')->whereIn('slug', $retired)->pluck('id');
            $targetId = DB::table('permissions')->where('slug', $target)->value('id');
            if ($retiredIds->isEmpty() || $targetId === null) {
                return;
            }
            $roleIds = DB::table('role_permissions')->whereIn('permission_id', $retiredIds)->distinct()->pluck('role_id');
            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $targetId]);
            }
        };
        $grantFor(self::RETIRED_TO_MANAGE, 'purchasing.rfq.manage');
        $grantFor(self::RETIRED_TO_VIEW, 'purchasing.rfq.view');

        $ids = DB::table('permissions')->whereIn('slug', [...self::RETIRED_TO_MANAGE, ...self::RETIRED_TO_VIEW])->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
