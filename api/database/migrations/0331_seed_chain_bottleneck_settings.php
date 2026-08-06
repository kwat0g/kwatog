<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $bottlenecks = [
            'so_at_mrp_planned' => ['label' => 'SO awaiting production', 'hours' => 48, 'audience' => 'ppc_head'],
            'wo_confirmed_unstarted' => ['label' => 'WO awaiting material issue', 'hours' => 24, 'audience' => 'warehouse_staff'],
            'inspection_outgoing_pending' => ['label' => 'Outgoing QC pending', 'hours' => 4, 'audience' => 'qc_inspector'],
            'delivery_scheduled_overdue' => ['label' => 'Delivery scheduled but not dispatched', 'hours' => 24, 'audience' => 'impex_officer'],
            'invoice_draft_overdue' => ['label' => 'Invoice draft awaiting finalization', 'hours' => 24, 'audience' => 'finance_officer'],
            'pr_pending_overdue' => ['label' => 'PR awaiting approval', 'hours' => 48, 'audience' => 'next_approver'],
            'bill_unpaid_overdue' => ['label' => 'Bill unpaid past due', 'hours' => 720, 'audience' => 'finance_officer'],
        ];
        DB::table('settings')->insertOrIgnore([
            'key' => 'dashboard.chain_bottlenecks', 'value' => json_encode($bottlenecks),
            'group' => 'dashboard', 'label' => 'Chain Bottleneck Policies',
            'description' => 'Threshold, label, and audience for chain bottleneck detection.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'dashboard.chain_bottlenecks')->delete();
    }
};
