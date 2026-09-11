<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * 2026-09-11 — drop the self-approval trap from the purchase_order chain.
     *
     * The seeded chain was purchasing_officer → finance_officer → vice_president.
     * purchasing_officer is the creator role (the only role holding
     * purchasing.po.create) and also holds purchasing.po.approve, so step 1
     * always named the submitter. ApprovalService's maker ≠ checker guard then
     * refused the only action the step accepts, and every manually raised PO
     * stalled at step 1 — invisible on the board with no way to advance for a
     * sole buyer. Finance and the VP are the spend checkers and neither creates
     * POs, so the chain is now money-only, mirroring the 2026-09-10 PR redesign.
     *
     * The buyer keeps purchasing.po.create and purchasing.po.approve; the latter
     * still drives the company-wide row scope in PurchaseOrderAccessPolicy.
     */
    public function up(): void
    {
        DB::table('workflow_definitions')
            ->where('workflow_type', 'purchase_order')
            ->update([
                'steps' => json_encode([
                    ['order' => 1, 'role' => 'finance_officer', 'label' => 'Finance'],
                    ['order' => 2, 'role' => 'vice_president', 'label' => 'VP', 'threshold' => '50000.00'],
                ]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('workflow_definitions')
            ->where('workflow_type', 'purchase_order')
            ->update([
                'steps' => json_encode([
                    ['order' => 1, 'role' => 'purchasing_officer', 'label' => 'Purchasing'],
                    ['order' => 2, 'role' => 'finance_officer', 'label' => 'Finance'],
                    ['order' => 3, 'role' => 'vice_president', 'label' => 'VP', 'threshold' => '50000.00'],
                ]),
                'updated_at' => now(),
            ]);
    }
};
