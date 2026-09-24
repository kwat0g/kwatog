<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->rewriteAssetDisposalWorkflow('system_admin', 'vice_president');

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['assets.transfer', 'assets.transfer.approve'])
            ->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }

    public function down(): void
    {
        $this->rewriteAssetDisposalWorkflow('vice_president', 'system_admin');
    }

    private function rewriteAssetDisposalWorkflow(string $from, string $to): void
    {
        $definition = DB::table('workflow_definitions')
            ->where('workflow_type', 'asset_disposal')
            ->first();
        if ($definition === null) {
            return;
        }

        // Do not rely on the shape of the old deployed definition. Some
        // installations still carry the retired four-step draft. New and
        // existing deployments must converge on the wired two-step chain.
        $steps = [
            ['order' => 1, 'role' => 'finance_officer', 'label' => 'Reviewed by'],
            ['order' => 2, 'role' => $to, 'label' => 'Approved by'],
        ];
        $snapshot = [
            'workflow_type' => 'asset_disposal',
            'name' => (string) $definition->name,
            'steps' => array_values($steps),
        ];
        $version = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));

        DB::table('workflow_definitions')
            ->where('id', $definition->id)
            ->update([
                'steps' => json_encode(array_values($steps), JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

        // Only current pending rows are migrated. Historical approval rows are
        // immutable evidence of who acted under the old deployment. Retired
        // extra steps are superseded rather than deleted, preserving history.
        $pending = DB::table('approval_records')
            ->where(function ($query) use ($definition): void {
                $query->where('workflow_definition_id', $definition->id)
                    // Pre-0476 pending rows have no workflow_definition_id;
                    // asset disposal is the only approval aggregate in this
                    // migration's scope, so they must not remain stranded.
                    ->orWhereNull('workflow_definition_id');
            })
            ->where('approvable_type', 'App\\Modules\\Assets\\Models\\Asset')
            ->where('is_current', true)
            ->where('action', 'pending')
            ->get();
        foreach ($pending as $row) {
            if ((int) $row->step_order > 2) {
                DB::table('approval_records')->where('id', $row->id)->update([
                    'action' => 'superseded',
                    'is_current' => false,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('approval_records')->where('id', $row->id)->update([
                'role_slug' => $steps[(int) $row->step_order - 1]['role'],
                'workflow_version' => $version,
                'workflow_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }
};
