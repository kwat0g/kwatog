<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NEW_COLUMNS = [
        'decision_code', 'policy_version', 'source_references', 'verification_context',
        'first_detected_at', 'last_evaluated_at', 'last_evaluated_run_id',
        'source_observed_at', 'business_due_at', 'resolution_evidence', 'verification_status',
        'predecessor_id', 'impact_currency', 'impact_metric',
    ];

    public function up(): void
    {
        $hasDuplicateActiveKeys = DB::table('dss_recommendations')
            ->select('detector_id', 'dedupe_key')
            ->whereIn('status', ['open', 'in_progress', 'snoozed'])
            ->groupBy('detector_id', 'dedupe_key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateActiveKeys) {
            throw new RuntimeException('Cannot expand DSS active dedupe index while duplicate active keys exist.');
        }

        Schema::table('dss_recommendations', function (Blueprint $table) {
            $table->string('decision_code', 100)->nullable();
            $table->string('policy_version', 40)->nullable();
            $table->jsonb('source_references')->nullable();
            $table->jsonb('verification_context')->nullable();
            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('last_evaluated_at')->nullable();
            $table->foreignId('last_evaluated_run_id')->nullable()->constrained('dss_run_logs')->nullOnDelete();
            $table->index('last_evaluated_run_id');
            $table->timestamp('source_observed_at')->nullable();
            $table->timestamp('business_due_at')->nullable();
            $table->jsonb('resolution_evidence')->nullable();
            $table->string('verification_status', 20)->nullable();
            $table->foreignId('predecessor_id')->nullable()->constrained('dss_recommendations')->nullOnDelete();
            $table->index('predecessor_id');
            $table->string('impact_currency', 3)->nullable();
            $table->string('impact_metric', 120)->nullable();
        });

        DB::statement('DROP INDEX dss_recommendations_open_dedupe_unique');
        DB::statement("CREATE UNIQUE INDEX dss_recommendations_open_dedupe_unique
            ON dss_recommendations (detector_id, dedupe_key)
            WHERE status IN ('open', 'in_progress', 'snoozed')");
    }

    public function down(): void
    {
        $hasData = DB::table('dss_recommendations')
            ->where(function ($query) {
                $query->whereIn('status', ['in_progress', 'resolved']);

                foreach (self::NEW_COLUMNS as $column) {
                    $query->orWhereNotNull($column);
                }
            })
            ->exists();

        if ($hasData) {
            throw new RuntimeException('Cannot roll back DSS lifecycle schema while lifecycle states or evidence are in use.');
        }

        DB::statement('DROP INDEX dss_recommendations_open_dedupe_unique');
        DB::statement("CREATE UNIQUE INDEX dss_recommendations_open_dedupe_unique
            ON dss_recommendations (detector_id, dedupe_key)
            WHERE status IN ('open', 'snoozed')");

        Schema::table('dss_recommendations', function (Blueprint $table) {
            $table->dropIndex(['last_evaluated_run_id']);
            $table->dropIndex(['predecessor_id']);
            $table->dropForeign(['last_evaluated_run_id']);
            $table->dropForeign(['predecessor_id']);
            $table->dropColumn(self::NEW_COLUMNS);
        });
    }
};
