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
        $this->addEmployeeTrainingColumns();
        $this->addEmployeeSkillColumns();
        $this->addAlertDeliveryTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('training_expiry_alert_deliveries');

        if (Schema::hasTable('employee_skills')) {
            Schema::table('employee_skills', function (Blueprint $table): void {
                $table->dropColumn([
                    'certification_document_name',
                    'certification_document_mime_type',
                    'certification_document_size',
                    'certification_document_uploaded_by',
                    'certification_document_uploaded_at',
                ]);
            });
        }

        if (Schema::hasTable('employee_trainings')) {
            Schema::table('employee_trainings', function (Blueprint $table): void {
                $table->dropUnique('uq_emp_training_assignment_key');
                $table->unique(
                    ['employee_id', 'training_id', 'scheduled_for'],
                    'uq_emp_training_scheduled',
                );
                $table->dropColumn([
                    'assignment_key',
                    'certificate_original_name',
                    'certificate_mime_type',
                    'certificate_size',
                    'certificate_uploaded_by',
                    'certificate_uploaded_at',
                ]);
            });
        }
    }

    private function addEmployeeTrainingColumns(): void
    {
        if (! Schema::hasTable('employee_trainings')) {
            return;
        }

        $duplicate = DB::table('employee_trainings')
            ->select('employee_id', 'training_id', 'scheduled_for', DB::raw('COUNT(*) AS row_count'))
            ->groupBy('employee_id', 'training_id', 'scheduled_for')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            $date = $duplicate->scheduled_for ?? 'unscheduled';
            throw new RuntimeException(
                "Cannot harden employee training uniqueness: duplicate employee {$duplicate->employee_id}, training {$duplicate->training_id}, date {$date}. Resolve duplicates before retrying.",
            );
        }

        Schema::table('employee_trainings', function (Blueprint $table): void {
            $table->string('assignment_key', 20)->default('__unscheduled__');
            $table->string('certificate_original_name')->nullable();
            $table->string('certificate_mime_type', 120)->nullable();
            $table->unsignedInteger('certificate_size')->nullable();
            $table->foreignId('certificate_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certificate_uploaded_at')->nullable();
        });

        DB::table('employee_trainings')->update([
            'assignment_key' => DB::raw("CASE WHEN scheduled_for IS NULL THEN '__unscheduled__' ELSE CAST(scheduled_for AS VARCHAR(10)) END"),
        ]);

        Schema::table('employee_trainings', function (Blueprint $table): void {
            $table->dropUnique('uq_emp_training_scheduled');
            $table->unique(
                ['employee_id', 'training_id', 'assignment_key'],
                'uq_emp_training_assignment_key',
            );
        });
    }

    private function addEmployeeSkillColumns(): void
    {
        if (! Schema::hasTable('employee_skills')) {
            return;
        }

        Schema::table('employee_skills', function (Blueprint $table): void {
            $table->string('certification_document_name')->nullable();
            $table->string('certification_document_mime_type', 120)->nullable();
            $table->unsignedInteger('certification_document_size')->nullable();
            $table->foreignId('certification_document_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('certification_document_uploaded_at')->nullable();
        });
    }

    private function addAlertDeliveryTable(): void
    {
        if (Schema::hasTable('training_expiry_alert_deliveries')) {
            return;
        }

        Schema::create('training_expiry_alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_training_id')->constrained('employee_trainings')->cascadeOnDelete();
            $table->string('alert_level', 10);
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('status', 20)->default('pending');
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(
                ['employee_training_id', 'alert_level', 'recipient_user_id', 'channel'],
                'uq_training_alert_delivery_target',
            );
            $table->index(['employee_training_id', 'alert_level'], 'ix_training_alert_delivery_record');
        });
    }
};
