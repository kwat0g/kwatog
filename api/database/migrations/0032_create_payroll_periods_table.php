<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('payroll_date');
            $table->boolean('is_first_half')->default(true);
            $table->boolean('is_thirteenth_month')->default(false);
            $table->string('status', 20)->default('draft'); // draft|processing|approved|finalized
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['period_start', 'period_end']);
            $table->index('status');
            $table->index('is_thirteenth_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_periods');
    }
};
