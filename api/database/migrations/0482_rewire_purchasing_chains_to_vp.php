<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * 2026-09-10 — PR/PO approval chain redesign.
     *
     * 1. Drop the two settings whose behaviour no longer exists:
     *    - approval.pr.dept_head_auto_approve_threshold (dead code PU-03; the
     *      department-head PR step is gone, so there is nothing to auto-skip)
     *    - purchasing.urgent_skip_limit (skipped the same now-deleted step;
     *      urgency remains a priority flag + notifications)
     * 2. Repoint the SLA escalation map: system_admin stood in for "VP" in
     *    every business escalation path. The seeded vice_president role now
     *    exists, so escalations land on an actual business executive —
     *    the original audit objection (A1) applied to this map too.
     */
    public function up(): void
    {
        DB::table('settings')->whereIn('key', [
            'approval.pr.dept_head_auto_approve_threshold',
            'purchasing.urgent_skip_limit',
        ])->delete();

        $map = json_encode([
            'department_head' => 'production_manager',
            'production_manager' => 'vice_president',
            'purchasing_officer' => 'vice_president',
            'finance_officer' => 'vice_president',
            'hr_officer' => 'vice_president',
            'ppc_head' => 'vice_president',
        ]);

        $updated = DB::table('settings')
            ->where('key', 'approvals.escalation.superior_role_map')
            ->update([
                'value' => $map,
                'description' => 'Role-to-role escalation map used when an approval SLA is breached.',
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            DB::table('settings')->insertOrIgnore([
                'key' => 'approvals.escalation.superior_role_map',
                'value' => $map,
                'group' => 'approval',
                'label' => 'Approval Escalation Superior Roles',
                'description' => 'Role-to-role escalation map used when an approval SLA is breached.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The removed settings are dead code by design; do not resurrect them.
        // Restore the legacy escalation map for rollback fidelity.
        DB::table('settings')
            ->where('key', 'approvals.escalation.superior_role_map')
            ->update([
                'value' => json_encode([
                    'department_head' => 'production_manager',
                    'production_manager' => 'system_admin',
                    'purchasing_officer' => 'system_admin',
                    'finance_officer' => 'system_admin',
                    'hr_officer' => 'system_admin',
                    'ppc_head' => 'system_admin',
                ]),
                'updated_at' => now(),
            ]);
    }
};
