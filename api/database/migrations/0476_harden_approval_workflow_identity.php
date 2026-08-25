<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table): void {
            // Existing test and locally-created definitions remain usable. The
            // seeder explicitly marks definitions that are not wired to a
            // submit path as inactive.
            $table->boolean('is_active')->default(true)->index();
        });

        Schema::table('approval_records', function (Blueprint $table): void {
            $table->unsignedInteger('attempt')->default(1);
            $table->boolean('is_current')->default(true);
            $table->foreignId('workflow_definition_id')
                ->nullable()
                ->constrained('workflow_definitions')
                ->nullOnDelete();
            $table->string('workflow_version', 64)->nullable();
            $table->json('workflow_snapshot')->nullable();

            $table->index(
                ['approvable_type', 'approvable_id', 'is_current', 'action'],
                'approval_records_current_action_idx',
            );
            $table->index(
                ['workflow_definition_id', 'workflow_version'],
                'approval_records_workflow_version_idx',
            );
        });

        $this->markLatestLegacyRowsCurrent();
    }

    public function down(): void
    {
        Schema::table('approval_records', function (Blueprint $table): void {
            $table->dropIndex('approval_records_current_action_idx');
            $table->dropIndex('approval_records_workflow_version_idx');
            $table->dropForeign(['workflow_definition_id']);
            $table->dropColumn([
                'attempt',
                'is_current',
                'workflow_definition_id',
                'workflow_version',
                'workflow_snapshot',
            ]);
        });

        Schema::table('workflow_definitions', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }

    /**
     * Legacy rows had no attempt marker. Treat the latest row for each
     * approvable step as current so a pre-migration resubmission cannot leave
     * two pending rows competing for the same step. New submissions stamp a
     * single attempt across every step.
     */
    private function markLatestLegacyRowsCurrent(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT
                        id,
                        ROW_NUMBER() OVER (
                            PARTITION BY approvable_type, approvable_id, step_order
                            ORDER BY created_at ASC, id ASC
                        ) AS attempt_no,
                        ROW_NUMBER() OVER (
                            PARTITION BY approvable_type, approvable_id, step_order
                            ORDER BY created_at DESC, id DESC
                        ) AS latest_no
                    FROM approval_records
                )
                UPDATE approval_records AS records
                SET attempt = ranked.attempt_no,
                    is_current = (ranked.latest_no = 1)
                FROM ranked
                WHERE records.id = ranked.id
            SQL);

            return;
        }

        // SQLite and MySQL installations used for local development may not
        // share PostgreSQL's UPDATE ... FROM syntax. The migration is a
        // one-time compatibility backfill; keep the portable fallback simple.
        $rows = DB::table('approval_records')
            ->orderBy('approvable_type')
            ->orderBy('approvable_id')
            ->orderBy('step_order')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'approvable_type', 'approvable_id', 'step_order']);

        $groups = [];
        foreach ($rows as $row) {
            $key = $row->approvable_type.'#'.$row->approvable_id.'#'.$row->step_order;
            $groups[$key][] = $row;
        }

        foreach ($groups as $group) {
            foreach ($group as $index => $row) {
                DB::table('approval_records')
                    ->where('id', $row->id)
                    ->update([
                        'attempt' => $index + 1,
                        'is_current' => $index === count($group) - 1,
                    ]);
            }
        }
    }
};
