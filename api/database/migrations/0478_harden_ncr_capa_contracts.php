<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NCR/CAPA hardening.
 *
 * The rows in this migration are operational evidence, not a cache:
 * escalation, recurrence work, and effectiveness reminders all need a
 * database identity that survives a worker retry or a second scheduler run.
 * Existing duplicate inspection links are rejected before the unique index is
 * added; this migration never chooses a business row to delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertValidNcrActionEffectivenessStatuses();

        $duplicates = DB::table('non_conformance_reports')
            ->select('inspection_id')
            ->whereNotNull('inspection_id')
            ->groupBy('inspection_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('inspection_id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot add ncr_inspection_unique: duplicate inspection-linked NCRs exist for inspection IDs '
                .implode(', ', $duplicates).'. Resolve them before retrying; no rows were changed.'
            );
        }

        Schema::table('non_conformance_reports', function (Blueprint $table): void {
            $table->string('defect_signature', 64)->nullable()->after('defect_description');
            $table->unique('inspection_id', 'ncr_inspection_unique');
            $table->index(['defect_signature', 'product_id', 'created_at'], 'ncr_recurrence_signature_idx');
        });

        Schema::create('ncr_recurrence_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ncr_id')
                ->unique()
                ->constrained('non_conformance_reports')
                ->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at'], 'ncr_recurrence_scan_queue_idx');
        });

        Schema::create('ncr_escalation_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ncr_id')
                ->constrained('non_conformance_reports')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('tier');
            $table->string('role', 50);
            $table->string('subject', 255);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('idempotency_key', 128)->unique();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['ncr_id', 'tier'], 'ncr_escalation_delivery_unique');
            $table->index(['status', 'last_attempted_at'], 'ncr_escalation_delivery_queue_idx');
        });

        Schema::create('ncr_effectiveness_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ncr_action_id')
                ->constrained('ncr_actions')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('notification_type', 50);
            $table->date('due_date');
            $table->string('idempotency_key', 160)->unique();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(
                ['ncr_action_id', 'user_id', 'notification_type', 'due_date'],
                'ncr_effectiveness_notification_unique',
            );
        });

        $this->addNcrActionEffectivenessCheck();
    }

    public function down(): void
    {
        $this->dropNcrActionEffectivenessCheck();

        Schema::dropIfExists('ncr_effectiveness_notifications');
        Schema::dropIfExists('ncr_escalation_deliveries');
        Schema::dropIfExists('ncr_recurrence_scans');

        Schema::table('non_conformance_reports', function (Blueprint $table): void {
            $table->dropIndex('ncr_recurrence_signature_idx');
            $table->dropUnique('ncr_inspection_unique');
            $table->dropColumn('defect_signature');
        });
    }

    private function addNcrActionEffectivenessCheck(): void
    {
        $allowed = "'pending_verification', 'effective', 'ineffective', 'not_applicable'";
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE "ncr_actions" ADD CONSTRAINT "ncr_actions_effectiveness_status_check" '
                .'CHECK ("effectiveness_status" IN ('.$allowed.') OR "effectiveness_status" IS NULL)'
            );
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE TRIGGER "ncr_actions_effectiveness_status_insert_guard" '
                .'BEFORE INSERT ON "ncr_actions" '
                .'WHEN NEW."effectiveness_status" IS NOT NULL '
                .'AND NEW."effectiveness_status" NOT IN ('.$allowed.') '
                .'BEGIN SELECT RAISE(ABORT, \'invalid ncr_actions.effectiveness_status\'); END'
            );
            DB::statement(
                'CREATE TRIGGER "ncr_actions_effectiveness_status_update_guard" '
                .'BEFORE UPDATE OF "effectiveness_status" ON "ncr_actions" '
                .'WHEN NEW."effectiveness_status" IS NOT NULL '
                .'AND NEW."effectiveness_status" NOT IN ('.$allowed.') '
                .'BEGIN SELECT RAISE(ABORT, \'invalid ncr_actions.effectiveness_status\'); END'
            );
        }
    }

    private function assertValidNcrActionEffectivenessStatuses(): void
    {
        $allowed = [
            'pending_verification',
            'effective',
            'ineffective',
            'not_applicable',
        ];
        $invalid = DB::table('ncr_actions')
            ->select('effectiveness_status', DB::raw('COUNT(*) AS row_count'))
            ->whereNotNull('effectiveness_status')
            ->whereNotIn('effectiveness_status', $allowed)
            ->groupBy('effectiveness_status')
            ->get();

        if ($invalid->isNotEmpty()) {
            $details = $invalid
                ->map(static fn (object $row): string => sprintf(
                    "'%s' (%d rows)",
                    str_replace("'", "''", (string) $row->effectiveness_status),
                    (int) $row->row_count,
                ))
                ->implode(', ');

            throw new RuntimeException(
                'Cannot add ncr_actions_effectiveness_status_check: unsupported values exist: '
                .$details.'. Resolve them before retrying; no rows were changed.'
            );
        }
    }

    private function dropNcrActionEffectivenessCheck(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE "ncr_actions" DROP CONSTRAINT IF EXISTS "ncr_actions_effectiveness_status_check"'
            );
            return;
        }

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS "ncr_actions_effectiveness_status_insert_guard"');
            DB::statement('DROP TRIGGER IF EXISTS "ncr_actions_effectiveness_status_update_guard"');
        }
    }
};
