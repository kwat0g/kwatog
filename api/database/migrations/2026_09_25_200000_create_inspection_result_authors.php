<?php

declare(strict_types=1);

use App\Modules\Quality\Models\InspectionMeasurement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_result_authors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inspection_id')->constrained('inspections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['inspection_id', 'user_id'], 'inspection_result_authors_unique');
            $table->index('user_id');
        });

        $this->backfillFromMeasurementAudit();
    }

    public function down(): void
    {
        if (DB::table('inspection_result_authors')->exists()) {
            throw new RuntimeException('Inspection result authorship is audit history and cannot be discarded by rollback.');
        }

        Schema::dropIfExists('inspection_result_authors');
    }

    private function backfillFromMeasurementAudit(): void
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasTable('inspection_measurements')) {
            return;
        }

        // Measurement creation seeds a blank checklist and is not result
        // authorship. Only user-attributed updates identify someone who later
        // entered or changed evidence. Unattributed legacy evidence cannot be
        // safely assigned to an editor, so it remains protected by inspector_id.
        DB::table('audit_logs')
            ->join('inspection_measurements', 'inspection_measurements.id', '=', 'audit_logs.model_id')
            ->where('audit_logs.model_type', InspectionMeasurement::class)
            ->where('audit_logs.action', 'updated')
            ->whereNotNull('audit_logs.user_id')
            ->select([
                'audit_logs.id as audit_id',
                'inspection_measurements.inspection_id',
                'audit_logs.user_id',
                'audit_logs.created_at',
                'audit_logs.new_values',
            ])
            ->orderBy('audit_logs.id')
            ->chunkById(500, function ($logs): void {
                $authors = [];
                foreach ($logs as $log) {
                    $changes = is_array($log->new_values)
                        ? $log->new_values
                        : json_decode((string) $log->new_values, true);
                    if (! is_array($changes)
                        || array_intersect(['measured_value', 'is_pass', 'notes'], array_keys($changes)) === []) {
                        continue;
                    }

                    $authors[] = [
                        'inspection_id' => $log->inspection_id,
                        'user_id' => $log->user_id,
                        'created_at' => $log->created_at,
                        'updated_at' => $log->created_at,
                    ];
                }

                if ($authors !== []) {
                    DB::table('inspection_result_authors')->insertOrIgnore($authors);
                }
            }, 'audit_logs.id', 'audit_id');
    }
};
