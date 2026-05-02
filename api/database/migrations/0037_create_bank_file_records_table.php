<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for generated bank disbursement files.
 * The file itself lives on the private storage disk; this table records who
 * generated which file for which period so we can reproduce or revoke later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_file_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods');
            $table->string('file_path');
            $table->integer('record_count');
            $table->decimal('total_amount', 15, 2);
            $table->foreignId('generated_by')->constrained('users');
            $table->timestamp('generated_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('payroll_period_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_file_records');
    }
};
