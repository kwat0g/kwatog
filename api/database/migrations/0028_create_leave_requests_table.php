<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('leave_request_no', 20)->unique();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 4, 1);
            $table->text('reason')->nullable();
            $table->string('document_path')->nullable();
            $table->string('status', 20)->default('pending_dept'); // pending_dept|pending_hr|approved|rejected|cancelled

            $table->foreignId('dept_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dept_approved_at')->nullable();
            $table->foreignId('hr_approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('hr_approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index('status');
            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
